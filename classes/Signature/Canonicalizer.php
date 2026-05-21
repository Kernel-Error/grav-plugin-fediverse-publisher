<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Signature;

/**
 * URL canonicalisation for the verifier.
 *
 * Two outputs:
 *
 * - `ownerUrl($url)` — fragment STRIPPED. Used for identity-binding
 *   comparison (ADR-002 R2-2): the canonical form of `keyId-without-
 *   fragment`, `publicKey.owner`, and `activity.actor` must all be
 *   byte-identical.
 *
 * - `keyId($url)` — fragment PRESERVED. Used for key-selection (the
 *   verifier-sketch R3-3 fix): when we fetch an actor doc, the
 *   `publicKey.id` must match the `keyId` we requested INCLUDING the
 *   `#main-key` (or similar) fragment.
 *
 * Both forms normalise scheme (https-only), host (IDNA + lowercase),
 * port (strip default 443), and path (trim trailing slash).
 * Returns the empty string on any input that cannot be canonicalised
 * — callers treat the empty string as a definite mismatch.
 */
final class Canonicalizer
{
    public static function ownerUrl(string $url): string
    {
        return self::canonicalize($url, stripFragment: true);
    }

    public static function keyId(string $url): string
    {
        return self::canonicalize($url, stripFragment: false);
    }

    /**
     * Returns just the authority — `https://host[:port]` — used for the
     * identity-binding check. Two URLs share authority iff they live
     * on the same host. This is the right comparison for keyId ↔
     * publicKey.owner ↔ activity.actor, regardless of whether the
     * server uses fragment-keyIds (Mastodon) or path-keyIds (GoToSocial,
     * Pleroma).
     *
     * Returns the empty string on input that fails canonicalisation.
     */
    public static function authority(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $parts = \parse_url($url);
        if (!\is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return '';
        }
        $rawHost = $parts['host'] ?? '';
        if ($rawHost === '') {
            return '';
        }
        $host = \idn_to_ascii(
            $rawHost,
            IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ,
            INTL_IDNA_VARIANT_UTS46,
        );
        if ($host === false || $host === '') {
            return '';
        }
        $host = \strtolower($host);
        $port = $parts['port'] ?? 443;
        return 'https://' . $host . ($port === 443 ? '' : ':' . $port);
    }

    private static function canonicalize(string $url, bool $stripFragment): string
    {
        if ($url === '') {
            return '';
        }
        $parts = \parse_url($url);
        if (!\is_array($parts)) {
            return '';
        }
        if (($parts['scheme'] ?? '') !== 'https') {
            return '';
        }
        $rawHost = $parts['host'] ?? '';
        if ($rawHost === '') {
            return '';
        }
        $host = \idn_to_ascii(
            $rawHost,
            IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ,
            INTL_IDNA_VARIANT_UTS46,
        );
        if ($host === false || $host === '') {
            return '';
        }
        $host = \strtolower($host);

        $port = $parts['port'] ?? 443;
        $portPart = $port === 443 ? '' : ':' . $port;

        $path = $parts['path'] ?? '';
        if ($path !== '/' && \str_ends_with($path, '/')) {
            $path = \rtrim($path, '/');
        }

        $result = 'https://' . $host . $portPart . $path;

        if (!$stripFragment && isset($parts['fragment']) && $parts['fragment'] !== '') {
            $result .= '#' . $parts['fragment'];
        }

        return $result;
    }
}
