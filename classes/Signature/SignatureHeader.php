<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Signature;

/**
 * Parsed `Signature:` header per draft-cavage-http-signatures-12.
 *
 * Header looks like:
 *   Signature: keyId="https://x/u#main-key",algorithm="rsa-sha256",
 *              headers="(request-target) host date digest",
 *              signature="base64..."
 *
 * Returns null on any malformed structure — caller treats that as 401.
 */
final class SignatureHeader
{
    /**
     * @param list<string> $headers Lowercased header names IN ORDER.
     */
    public function __construct(
        public readonly string $keyId,
        public readonly string $algorithm,
        public readonly array $headers,
        public readonly string $signature,
    ) {
    }

    public static function parse(string $headerValue): ?self
    {
        if ($headerValue === '') {
            return null;
        }
        $params = [];
        // Splitting on top-level commas. Cavage-12 doesn't allow commas
        // inside quoted values, but we use a non-greedy quoted-value
        // pattern to be safe against weird encodings.
        if (!\preg_match_all(
            '/(?P<key>[a-zA-Z][a-zA-Z0-9_-]*)\s*=\s*"(?P<val>(?:[^"\\\\]|\\\\.)*)"/',
            $headerValue,
            $matches,
            PREG_SET_ORDER,
        )) {
            return null;
        }
        foreach ($matches as $m) {
            $params[\strtolower($m['key'])] = \stripslashes($m['val']);
        }

        $keyId     = $params['keyid']     ?? '';
        $algorithm = \strtolower($params['algorithm'] ?? '');
        $signature = $params['signature'] ?? '';
        $headers   = \strtolower(\trim($params['headers'] ?? ''));

        if ($keyId === '' || $signature === '' || $headers === '') {
            return null;
        }

        $headerList = \array_values(\array_filter(\explode(' ', $headers), static fn(string $s): bool => $s !== ''));
        if ($headerList === []) {
            return null;
        }

        return new self($keyId, $algorithm, $headerList, $signature);
    }
}
