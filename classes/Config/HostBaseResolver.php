<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Config;

/**
 * Resolves the canonical host base (`https://host[:port]`) used to
 * build every public URL the plugin emits: actor URL, keyId, inbox,
 * outbox, followers/following collections. The resolved value lands
 * in the AS 2.0 JSON-LD that goes out on the wire AND in the keyId
 * we sign with — peers dereference both, so the URL must be
 * publicly reachable from anywhere on the Fediverse.
 *
 * Why this is its own class: under CLI (cron scheduler, drain
 * command) Grav's `$grav['uri']->rootUrl(true)` returns empty and
 * `$_SERVER['HTTP_HOST']` is also empty — so the older inline
 * resolver fell through to `'localhost'`. Mastodon receives a
 * `keyId=http://localhost/...` and refuses with 401 (private-
 * network reference). The fix is to require the operator to set a
 * deterministic, SAPI-independent canonical host and reject
 * anything that resolves to localhost.
 *
 * Pure functions, every input passed in — unit-testable without
 * Grav loaded.
 */
final class HostBaseResolver
{
    /** Hosts we always reject for use as a public hostBase. */
    private const LOOPBACK_HOSTS = [
        'localhost',
        '127.0.0.1',
        '::1',
        '0.0.0.0',
    ];

    /**
     * Resolve the canonical hostBase. Priority order:
     *
     *   1. Operator-configured `federation.canonical_host` — the
     *      only source that is consistent across web AND CLI.
     *   2. Grav's `$grav['uri']->rootUrl(true)` — present in web
     *      context, empty under CLI.
     *   3. `$_SERVER['HTTP_HOST']` + scheme — also web-only.
     *   4. Sentinel `http://localhost` — never usable for
     *      federation, but returned so caller code has a string;
     *      preflight is where this gets rejected, not here.
     *
     * Returns the value without a trailing slash. Scheme is forced
     * to https when the operator wrote a bare host (`blog.local`
     * → `https://blog.local`). HTTP is allowed but only when
     * explicitly written that way — Fediverse peers reject http
     * keyIds anyway.
     */
    public static function resolve(
        string $configuredCanonical,
        string $gravRootUrl,
        bool $serverHttps,
        string $serverHost,
    ): string {
        $configured = self::normaliseCanonical($configuredCanonical);
        if ($configured !== '') {
            return $configured;
        }

        $gravRootUrl = rtrim(trim($gravRootUrl), '/');
        if ($gravRootUrl !== '') {
            return $gravRootUrl;
        }

        $serverHost = trim($serverHost);
        if ($serverHost !== '') {
            return ($serverHttps ? 'https://' : 'http://') . $serverHost;
        }

        return 'http://localhost';
    }

    /**
     * Does the resolved hostBase look like something we could
     * publish on the wire? Used by PreflightCheck — the resolver
     * itself doesn't reject; the resolver always returns a string,
     * and the preflight decides whether to enable the plugin.
     */
    public static function isPublishable(string $hostBase): bool
    {
        if ($hostBase === '') {
            return false;
        }
        $parts = parse_url($hostBase);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https') {
            return false;
        }
        // ActivityPub + WebFinger require Grav at the document root
        // (ADR-004 A-4). canonical_host must be `https://host` only —
        // a path segment or port would diverge from what the WebFinger
        // host comparison and the spec-required `.well-known/*` paths
        // assume. Reject both to keep the contract coherent across
        // resolver, preflight, WebFinger, and admin validation.
        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            return false;
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        if (isset($parts['port'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        // IPv6 literals in URLs are bracketed (`[::1]`). parse_url
        // keeps the brackets; strip them for IP comparisons.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if (\in_array($host, self::LOOPBACK_HOSTS, true)) {
            return false;
        }
        // Reject private-network literals — Mastodon checks for these
        // server-side and refuses any keyId pointing into RFC 1918,
        // CGNAT, ULA, or link-local space.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host);
        }
        // Reject raw Unicode hosts: the operator must publish an
        // A-label (xn--…). Mixed-case is fine, the resolver lower-
        // cases the host already. Anything outside the LDH-with-dot
        // ASCII set is a homograph footgun.
        if (!preg_match('/^[a-z0-9.\-]+$/', $host)) {
            return false;
        }
        return true;
    }

    private static function isPublicIp(string $ip): bool
    {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
    }

    /**
     * Normalise an operator-typed canonical-host value. Returns ''
     * if the value is meaningfully empty. Otherwise: forces https
     * scheme when the operator wrote a bare host, strips trailing
     * slash, lowercases the host part.
     */
    private static function normaliseCanonical(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $value)) {
            $value = 'https://' . $value;
        }
        $value = rtrim($value, '/');
        // Lowercase the host segment, leave path/query as-is. We
        // don't expect path/query, but if the operator includes
        // one we don't want to silently mangle it.
        $parts = parse_url($value);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            // Unparseable — return as-typed and let preflight reject.
            return $value;
        }
        $out = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (isset($parts['port'])) {
            $out .= ':' . $parts['port'];
        }
        if (isset($parts['path'])) {
            $out .= $parts['path'];
        }
        return rtrim($out, '/');
    }
}
