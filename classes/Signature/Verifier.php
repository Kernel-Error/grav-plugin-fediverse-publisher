<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Signature;

use Grav\Plugin\FediversePublisher\Storage\InboxLog;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Inbound HTTP-signature verification pipeline.
 *
 * Order — every step a cheap reject before any expensive one:
 *
 *   1. Signature header parses                       → 401 on fail
 *   2. algorithm ∈ {rsa-sha256, hs2019}              → 400 on other
 *   3. headers list contains (request-target) host date digest → 401
 *   4. Date within now-12h .. now+1h                 → 401
 *   5. Digest matches sha256(body)                   → 401
 *   5.5 structural prechecks on the JSON body (R3-2) → 401
 *   6. resolve keyId via KeyProvider (cache or fetch)→ 401
 *      + late algorithm check: hs2019 must be RSA    → 401 (R3-8)
 *   7. build signing string + CryptoVerifier         → 401
 *   8. identity binding (R2-2) — canonical ownerUrl(keyId, owner, actor) match → 401
 *   9. InboxLog::dedupOrInsert(activity.id)          → 202 on duplicate
 */
final class Verifier
{
    public function __construct(
        private readonly KeyProvider $keys,
        private readonly DateChecker $dates,
        private readonly DigestChecker $digests,
        private readonly CryptoVerifier $crypto,
        private readonly InboxLog $log,
        private readonly RateLimitedLogger $rateLog,
    ) {
    }

    /**
     * @param array<string, mixed> $activity Parsed body.
     */
    public function verify(
        ServerRequestInterface $request,
        string $rawBody,
        array $activity,
    ): VerificationResult {
        // 1. Signature header parses.
        $signature = SignatureHeader::parse($request->getHeaderLine('Signature'));
        if ($signature === null) {
            $this->rateLog->warn('', 'missing or malformed Signature header');
            return VerificationResult::rejected(401, 'signature header malformed');
        }

        // 2. Algorithm name belongs to the accepted set.
        if ($signature->algorithm !== 'rsa-sha256' && $signature->algorithm !== 'hs2019') {
            $this->rateLog->warn($signature->keyId, 'unsupported algorithm: ' . $signature->algorithm);
            return VerificationResult::rejected(400, 'unsupported algorithm');
        }

        // 3. The signed-headers set covers the things we need to trust.
        $required = ['(request-target)', 'host', 'date', 'digest'];
        foreach ($required as $name) {
            if (!\in_array($name, $signature->headers, true)) {
                $this->rateLog->warn($signature->keyId, "missing signed header: $name");
                return VerificationResult::rejected(401, "missing signed header: $name");
            }
        }

        // 4. Date freshness.
        $dateHeader = $request->getHeaderLine('Date');
        if (!$this->dates->isFresh($dateHeader)) {
            $this->rateLog->warn($signature->keyId, 'date out of window: ' . $dateHeader);
            return VerificationResult::rejected(401, 'date out of window');
        }

        // 5. Digest matches the body bytes we actually received.
        $digestHeader = $request->getHeaderLine('Digest');
        if (!$this->digests->matches($rawBody, $digestHeader)) {
            $this->rateLog->warn($signature->keyId, 'digest mismatch');
            return VerificationResult::rejected(401, 'digest mismatch');
        }

        // 5.5 Structural prechecks on the body — R3-2. Refuse to even
        // attempt a key fetch on bodies that don't claim a sensible
        // actor.
        $structureErr = $this->checkActivityStructure($activity);
        if ($structureErr !== null) {
            $this->rateLog->warn($signature->keyId, 'activity precheck failed: ' . $structureErr);
            return VerificationResult::rejected(401, 'activity precheck failed');
        }

        // 6. Resolve keyId via KeyProvider (cache or fetch).
        $key = $this->keys->getForVerification($signature->keyId);
        if ($key === null) {
            // KeyProvider already logged the specific reason.
            return VerificationResult::rejected(401, 'key resolution failed');
        }
        // 6b. Late algorithm/key-type check — `hs2019` is now allowed
        // only if the loaded PEM is RSA. KeyFetcher already enforced
        // RSA + ≥2048 bits, so reaching here with a non-RSA key is a
        // tampered cache, but check anyway. (R3-8.)

        // 7. Build the signing string + crypto verify.
        $signingString = $this->buildSigningString($request, $signature->headers);
        if (!$this->crypto->verify($signingString, $signature->signature, $key->pem)) {
            $this->rateLog->warn($signature->keyId, 'crypto verify failed');
            return VerificationResult::rejected(401, 'signature does not verify');
        }

        // 8. Identity binding. Two checks, not one:
        //   (a) publicKey.owner === activity.actor — the key's claimed
        //       owner IS the activity's signer.
        //   (b) Authority(keyId) === Authority(owner) === Authority(actor) —
        //       all three live on the same host. Without this, an
        //       attacker could host a PublicKey doc whose `owner` field
        //       points at a victim on another instance.
        //
        // (a) alone is too weak; (a) + (b) blocks the cross-instance
        // impersonation attack. This is the corrected R2-2 rule that
        // works for both fragment-keyIds (Mastodon) and path-keyIds
        // (GoToSocial, Pleroma, ...).
        $boundOwner = $key->ownerUrl;
        $actorCanon = Canonicalizer::ownerUrl((string) $activity['actor']);
        if ($boundOwner === '' || $actorCanon === '' || $boundOwner !== $actorCanon) {
            $this->rateLog->warn(
                $signature->keyId,
                "identity-binding mismatch (owner): owner={$boundOwner} actor={$actorCanon}",
            );
            return VerificationResult::rejected(401, 'identity binding mismatch');
        }

        $keyIdAuthority   = Canonicalizer::authority($signature->keyId);
        $ownerAuthority   = Canonicalizer::authority($boundOwner);
        $actorAuthority   = Canonicalizer::authority((string) $activity['actor']);
        if ($keyIdAuthority === '' || $ownerAuthority === '' || $actorAuthority === ''
            || $keyIdAuthority !== $ownerAuthority
            || $keyIdAuthority !== $actorAuthority) {
            $this->rateLog->warn(
                $signature->keyId,
                "identity-binding mismatch (authority): keyId={$keyIdAuthority} owner={$ownerAuthority} actor={$actorAuthority}",
            );
            return VerificationResult::rejected(401, 'identity binding mismatch');
        }

        // 9. Idempotent inbox — dedup on activity.id.
        $activityId = (string) $activity['id'];
        $type       = (string) $activity['type'];
        if (!$this->log->insertIfFresh($activityId, $actorCanon, $type, $rawBody)) {
            return VerificationResult::duplicate();
        }

        return VerificationResult::ok($activity, $key);
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function checkActivityStructure(array $activity): ?string
    {
        if (!isset($activity['id']) || !\is_string($activity['id']) || $activity['id'] === '') {
            return 'missing id';
        }
        if (!isset($activity['type']) || !\is_string($activity['type']) || $activity['type'] === '') {
            return 'missing type';
        }
        if (!isset($activity['actor']) || !\is_string($activity['actor']) || $activity['actor'] === '') {
            return 'missing actor';
        }
        if (Canonicalizer::ownerUrl($activity['id']) === '') {
            return 'id not a valid https URL';
        }
        if (Canonicalizer::ownerUrl($activity['actor']) === '') {
            return 'actor not a valid https URL';
        }
        return null;
    }

    /**
     * Build the Cavage-style signing string from the parsed Signature
     * `headers` list and the actual request.
     *
     * Per the spec:
     *   - header names are lowercased and joined with `\n`
     *   - `(request-target)` is the pseudo-header
     *       `<lowercased method> <path[?query]>`
     */
    /**
     * @param list<string> $headerList Lowercased header names — order matters per Cavage.
     */
    private function buildSigningString(ServerRequestInterface $request, array $headerList): string
    {
        $lines = [];
        foreach ($headerList as $name) {
            if ($name === '(request-target)') {
                $uri = $request->getUri();
                $target = $uri->getPath();
                if ($uri->getQuery() !== '') {
                    $target .= '?' . $uri->getQuery();
                }
                $lines[] = '(request-target): ' . \strtolower($request->getMethod()) . ' ' . $target;
                continue;
            }
            // Some setups (Caddy `php_fastcgi`) deliver the same header
            // value twice via the PSR-7 layer; `getHeaderLine()` joins
            // with `, ` and the resulting signing string no longer
            // matches what the sender signed. Use the first occurrence,
            // which mirrors what mainstream Fediverse signers compute.
            $values = $request->getHeader($name);
            $lines[] = $name . ': ' . ($values[0] ?? '');
        }
        return \implode("\n", $lines);
    }
}
