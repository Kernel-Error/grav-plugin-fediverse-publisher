<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Signature;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * SSRF-hardened HTTPS GET of a remote actor document. Implements the
 * controls from ADR-002 A-1 and the R2-3 / R3-3 / R3-5 / R3-6 / R3-7
 * additions from the verifier-sketch round-3 corrections.
 *
 * Behaviour matrix:
 *
 *   scheme != https                  → KeyFetchException
 *   non-443 port                     → KeyFetchException (R3-5, strict v0.1)
 *   host fails IDNA UTS46            → KeyFetchException
 *   resolves to private/reserved IP  → KeyFetchException (RFC 1918 + IPv6 ULA + IPv4-mapped reserved)
 *   redirect                         → KeyFetchException (allow_redirects=false)
 *   body > 64 KiB                    → KeyFetchException
 *   Content-Type not AP-flavoured    → KeyFetchException (R3-6, strict parse)
 *   PEM fails openssl parse / non-RSA / <2048 bits  → KeyFetchException (R3-7)
 *   publicKey.id != requested keyId  → KeyFetchException (R3-3, fragment-aware)
 */
final class KeyFetcher
{
    public const MAX_BYTES                 = 65_536;   // 64 KiB
    public const CONNECT_TIMEOUT_SECONDS   = 5;
    public const TOTAL_TIMEOUT_SECONDS     = 10;
    public const MIN_RSA_BITS              = 2048;

    /**
     * @param list<string> $allowedReservedCidrs CIDR ranges (IPv4 only for v0.1)
     *        that override the reserved-IP block. Dev-only; in production
     *        this stays empty.
     * @param string       $userAgent             Outbound User-Agent. Should
     *        identify the plugin + the local site URL so receivers can find
     *        someone to contact. Plugin entry wires the actual host at runtime.
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly Clock $clock,
        /** @var callable(string):list<string> Resolves a hostname to a list of IPs. */
        private $resolver,
        private readonly array $allowedReservedCidrs = [],
        private readonly string $userAgent = 'grav-plugin-fediverse-publisher/0.0.1',
    ) {
    }

    public function fetch(string $keyId): FetchedKey
    {
        $canonicalKeyId = Canonicalizer::keyId($keyId);
        if ($canonicalKeyId === '') {
            throw new KeyFetchException('keyId rejected by canonicaliser');
        }
        $parts = \parse_url($canonicalKeyId);
        $host  = (string) ($parts['host'] ?? '');
        $port  = $parts['port'] ?? 443;
        if ($port !== 443) {
            throw new KeyFetchException("non-443 port $port refused (v0.1 strict)");
        }

        // Resolve + SSRF-filter IPs. Pin the first allowed IP.
        $ips = ($this->resolver)($host);
        if ($ips === []) {
            throw new KeyFetchException("DNS resolution failed for $host");
        }
        $pinned = null;
        foreach ($ips as $ip) {
            if (!$this->isReservedIp($ip)) {
                $pinned = $ip;
                break;
            }
        }
        if ($pinned === null) {
            throw new KeyFetchException("all resolved IPs for $host are reserved");
        }

        // Fetch over Guzzle with the pinned IP. SNI + cert validation
        // still happens against $host because of CURLOPT_RESOLVE
        // semantics.
        $response = $this->http->request('GET', $canonicalKeyId, [
            'curl' => [
                CURLOPT_RESOLVE => ["{$host}:443:{$pinned}"],
            ],
            'allow_redirects' => false,
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'timeout'         => self::TOTAL_TIMEOUT_SECONDS,
            'headers' => [
                'Accept'     => 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
                'User-Agent' => $this->userAgent,
            ],
            'http_errors' => false,
            'stream'      => true,
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new KeyFetchException('upstream returned ' . $response->getStatusCode());
        }
        if (!MediaType::isActivityPubJson($response->getHeaderLine('Content-Type'))) {
            throw new KeyFetchException('unexpected content-type: ' . $response->getHeaderLine('Content-Type'));
        }

        $payload = $this->readCapped($response);
        $doc = \json_decode($payload, true);
        if (!\is_array($doc)) {
            throw new KeyFetchException('actor doc not JSON object');
        }

        // Three response shapes seen in the wild:
        //
        //  A. Mastodon-style "full actor doc":
        //     the keyId is a `#main-key` fragment of the actor's URL,
        //     and fetching it gives back the full actor — `inbox` +
        //     `publicKey` block both present.
        //
        //  B. Standalone PublicKey object:
        //     some peers serve the keyId URL as a flat JSON-LD with
        //     `id`/`owner`/`publicKeyPem` at the top level. No nested
        //     publicKey, no inbox.
        //
        //  C. **Partial actor doc** (GoToSocial 0.21, authorized-fetch
        //     mode by default):
        //     the keyId returns the actor's `id` + `publicKey` block
        //     but strips inbox/outbox/etc. The full actor requires a
        //     signed GET (deferred to v0.2 per ADR-002 §6).
        //     For v0.1 we use the convention `owner + "/inbox"` —
        //     accurate for Mastodon / GoToSocial / Pleroma / Lemmy /
        //     Friendica. Non-standard inbox paths break here; signed
        //     GET in v0.2 fixes that properly.
        $hasInbox        = isset($doc['inbox'])      && \is_string($doc['inbox']);
        $hasNestedPubKey = \is_array($doc['publicKey'] ?? null);
        $hasFlatPemPair  = isset($doc['publicKeyPem'], $doc['owner'])
                           && \is_string($doc['publicKeyPem']) && \is_string($doc['owner']);

        if ($hasInbox && $hasNestedPubKey) {
            return $this->buildFromActorDoc($doc, $canonicalKeyId);
        }
        if ($hasFlatPemPair) {
            return $this->buildFromStandalonePublicKey($doc, $canonicalKeyId);
        }
        if ($hasNestedPubKey && isset($doc['id']) && \is_string($doc['id'])) {
            return $this->buildFromPartialActor($doc, $canonicalKeyId);
        }
        throw new KeyFetchException('unexpected actor-doc shape (no inbox, no publicKey)');
    }

    /**
     * Partial actor doc handler (shape C). Uses the embedded publicKey
     * verbatim and synthesises the inbox URL via the de-facto Fediverse
     * convention `<owner>/inbox`. v0.2 replaces this with a signed GET
     * of the owner URL.
     *
     * @param array<string, mixed> $doc
     */
    private function buildFromPartialActor(array $doc, string $canonicalKeyId): FetchedKey
    {
        $pk = $doc['publicKey'] ?? null;
        if (\is_array($pk) && isset($pk[0])) {
            $pk = $pk[0];
        }
        if (!\is_array($pk) || !isset($pk['publicKeyPem'], $pk['id'], $pk['owner'])) {
            throw new KeyFetchException('partial actor doc missing publicKey block');
        }
        if (Canonicalizer::keyId((string) $pk['id']) !== $canonicalKeyId) {
            throw new KeyFetchException('publicKey.id does not match requested keyId');
        }

        $pem = (string) $pk['publicKeyPem'];
        $this->validateRsaPem($pem);

        $owner       = Canonicalizer::ownerUrl((string) $pk['owner']);
        $inboxUrl    = \rtrim($owner, '/') . '/inbox';
        $sharedInbox = $doc['endpoints']['sharedInbox'] ?? null;

        return new FetchedKey(
            keyId:          $canonicalKeyId,
            ownerUrl:       $owner,
            pem:            $pem,
            inboxUrl:       $inboxUrl,
            sharedInboxUrl: \is_string($sharedInbox) && $sharedInbox !== '' ? $sharedInbox : null,
            fetchedAt:      $this->clock->now(),
        );
    }

    /**
     * @param array<string, mixed> $doc Full Actor JSON-LD
     */
    private function buildFromActorDoc(array $doc, string $canonicalKeyId): FetchedKey
    {
        $pk = $doc['publicKey'] ?? null;
        if (\is_array($pk) && isset($pk[0])) {
            $pk = $pk[0];
        }
        if (!\is_array($pk) || !isset($pk['publicKeyPem'], $pk['id'], $pk['owner'])) {
            throw new KeyFetchException('actor doc missing publicKey block');
        }
        if (Canonicalizer::keyId((string) $pk['id']) !== $canonicalKeyId) {
            throw new KeyFetchException('publicKey.id does not match requested keyId');
        }

        $pem = (string) $pk['publicKeyPem'];
        $this->validateRsaPem($pem);

        $sharedInbox = $doc['endpoints']['sharedInbox'] ?? null;

        return new FetchedKey(
            keyId:          $canonicalKeyId,
            ownerUrl:       Canonicalizer::ownerUrl((string) $pk['owner']),
            pem:            $pem,
            inboxUrl:       (string) $doc['inbox'],
            sharedInboxUrl: \is_string($sharedInbox) && $sharedInbox !== '' ? $sharedInbox : null,
            fetchedAt:      $this->clock->now(),
        );
    }

    /**
     * @param array<string, mixed> $pkDoc Standalone PublicKey JSON-LD
     */
    private function buildFromStandalonePublicKey(array $pkDoc, string $canonicalKeyId): FetchedKey
    {
        if (Canonicalizer::keyId((string) ($pkDoc['id'] ?? '')) !== $canonicalKeyId) {
            throw new KeyFetchException('publicKey.id does not match requested keyId');
        }
        $pem = (string) $pkDoc['publicKeyPem'];
        $this->validateRsaPem($pem);

        // Second fetch — re-do all SSRF checks for the owner URL too.
        $ownerUrl = (string) $pkDoc['owner'];
        $actorDoc = $this->fetchActorJson($ownerUrl);

        $sharedInbox = $actorDoc['endpoints']['sharedInbox'] ?? null;
        $inbox = $actorDoc['inbox'] ?? null;
        if (!\is_string($inbox) || $inbox === '') {
            throw new KeyFetchException('owner doc has no inbox URL');
        }
        // Cross-check: the actor doc's publicKey must reference back to
        // the keyId we just verified, otherwise an attacker could host
        // a PublicKey doc whose `owner` points at a victim.
        $actorPk = $actorDoc['publicKey'] ?? null;
        if (\is_array($actorPk) && isset($actorPk[0])) {
            $actorPk = $actorPk[0];
        }
        if (!\is_array($actorPk)
            || Canonicalizer::keyId((string) ($actorPk['id'] ?? '')) !== $canonicalKeyId) {
            throw new KeyFetchException("actor doc does not reference the requested keyId");
        }

        return new FetchedKey(
            keyId:          $canonicalKeyId,
            ownerUrl:       Canonicalizer::ownerUrl($ownerUrl),
            pem:            $pem,
            inboxUrl:       $inbox,
            sharedInboxUrl: \is_string($sharedInbox) && $sharedInbox !== '' ? $sharedInbox : null,
            fetchedAt:      $this->clock->now(),
        );
    }

    /**
     * Stripped-down SSRF-hardened GET, returns the decoded JSON.
     * Used for the second hop in the standalone-PublicKey flow.
     *
     * @return array<string, mixed>
     */
    private function fetchActorJson(string $url): array
    {
        $canonical = Canonicalizer::keyId($url);
        if ($canonical === '') {
            throw new KeyFetchException('owner URL rejected by canonicaliser');
        }
        $parts = \parse_url($canonical);
        $host  = (string) ($parts['host'] ?? '');
        $port  = $parts['port'] ?? 443;
        if ($port !== 443) {
            throw new KeyFetchException("non-443 port $port refused (owner)");
        }
        $ips = ($this->resolver)($host);
        if ($ips === []) {
            throw new KeyFetchException("DNS resolution failed for owner host $host");
        }
        $pinned = null;
        foreach ($ips as $ip) {
            if (!$this->isReservedIp($ip)) {
                $pinned = $ip;
                break;
            }
        }
        if ($pinned === null) {
            throw new KeyFetchException("all resolved IPs for $host are reserved");
        }

        $response = $this->http->request('GET', $canonical, [
            'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$pinned}"]],
            'allow_redirects' => false,
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'timeout'         => self::TOTAL_TIMEOUT_SECONDS,
            'headers' => [
                'Accept'     => 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
                'User-Agent' => $this->userAgent,
            ],
            'http_errors' => false,
            'stream'      => true,
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new KeyFetchException('owner upstream returned ' . $response->getStatusCode());
        }
        if (!MediaType::isActivityPubJson($response->getHeaderLine('Content-Type'))) {
            throw new KeyFetchException('owner unexpected content-type');
        }
        $payload = $this->readCapped($response);
        $doc = \json_decode($payload, true);
        if (!\is_array($doc)) {
            throw new KeyFetchException('owner doc not JSON object');
        }
        return $doc;
    }

    private function readCapped(ResponseInterface $response): string
    {
        $body = $response->getBody();
        $payload = '';
        while (!$body->eof() && \strlen($payload) <= self::MAX_BYTES) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                break;
            }
            $payload .= $chunk;
        }
        if (\strlen($payload) > self::MAX_BYTES) {
            throw new KeyFetchException('actor doc exceeds ' . self::MAX_BYTES . ' bytes');
        }
        return $payload;
    }

    private function validateRsaPem(string $pem): void
    {
        if ($pem === '') {
            throw new KeyFetchException('publicKeyPem is empty');
        }
        $key = @\openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new KeyFetchException('publicKeyPem failed openssl parse');
        }
        $details = \openssl_pkey_get_details($key);
        if (!\is_array($details)) {
            throw new KeyFetchException('publicKeyPem details unavailable');
        }
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new KeyFetchException('publicKeyPem is not RSA');
        }
        if (($details['bits'] ?? 0) < self::MIN_RSA_BITS) {
            throw new KeyFetchException('RSA key smaller than ' . self::MIN_RSA_BITS . ' bits');
        }
    }

    /**
     * Reject loopback, link-local, private, multicast, reserved,
     * including IPv4-mapped IPv6 forms (R2-3 / R3 #5). Operators
     * may opt-out per-CIDR via `$allowedReservedCidrs` — dev-only.
     */
    private function isReservedIp(string $ip): bool
    {
        if ($this->isInAllowList($ip)) {
            return false;
        }
        if (\filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return !\filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );
        }
        if (\filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->isReservedIpv6($ip);
        }
        return true;   // unknown family → reject
    }

    private function isInAllowList(string $ip): bool
    {
        if ($this->allowedReservedCidrs === []) {
            return false;
        }
        if (!\filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;   // IPv6 allow-list not supported in v0.1
        }
        $ipLong = \ip2long($ip);
        if ($ipLong === false) {
            return false;
        }
        foreach ($this->allowedReservedCidrs as $cidr) {
            if (!\is_string($cidr) || !\str_contains($cidr, '/')) {
                continue;
            }
            [$net, $bits] = \explode('/', $cidr, 2);
            $netLong = \ip2long($net);
            $bits    = (int) $bits;
            if ($netLong === false || $bits < 0 || $bits > 32) {
                continue;
            }
            $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;
            if (($ipLong & $mask) === ($netLong & $mask)) {
                return true;
            }
        }
        return false;
    }

    private function isReservedIpv6(string $ip): bool
    {
        $normalised = \strtolower($ip);
        if ($normalised === '::1' || $normalised === '::') {
            return true;
        }

        // Try IPv4-mapped / -compatible (e.g. ::ffff:10.0.0.1)
        if (\str_starts_with($normalised, '::ffff:') || \str_starts_with($normalised, '::')) {
            $tail = \substr($ip, \strrpos($ip, ':') + 1);
            if (\filter_var($tail, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return !\filter_var(
                    $tail,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
                );
            }
        }

        // Parse into bytes for prefix checks (link-local fe80::/10,
        // ULA fc00::/7, multicast ff00::/8).
        $bytes = @\inet_pton($ip);
        if ($bytes === false || \strlen($bytes) !== 16) {
            return true;
        }
        $b0 = \ord($bytes[0]);
        $b1 = \ord($bytes[1]);

        if ($b0 === 0xff) {                                  // ff00::/8 multicast
            return true;
        }
        if ($b0 === 0xfe && ($b1 & 0xc0) === 0x80) {          // fe80::/10 link-local
            return true;
        }
        if (($b0 & 0xfe) === 0xfc) {                          // fc00::/7 ULA
            return true;
        }
        return false;
    }
}
