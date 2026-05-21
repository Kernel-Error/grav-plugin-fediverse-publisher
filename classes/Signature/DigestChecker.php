<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Signature;

/**
 * Verifies the `Digest` header against the actual request body. Format
 * per draft-cavage-http-signatures-12 / RFC 3230:
 *   Digest: SHA-256=<base64(sha256(raw_body))>
 *
 * Compared with `hash_equals` so a length difference in the digest
 * value doesn't reveal anything via timing.
 */
final class DigestChecker
{
    public function matches(string $rawBody, string $digestHeader): bool
    {
        if (!preg_match('/^SHA-256=([A-Za-z0-9+\/=]+)$/i', trim($digestHeader), $m)) {
            return false;
        }
        $expected = base64_encode(hash('sha256', $rawBody, true));
        return hash_equals($expected, $m[1]);
    }
}
