<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Config;

use Grav\Plugin\FediversePublisher\Config\HostBaseResolver;
use PHPUnit\Framework\TestCase;

/**
 * Covers exactly the matrix that the v0.0.4 production deploy
 * stubbed its toe on: the resolver MUST produce a deterministic,
 * publishable hostBase under CLI (no `$_SERVER['HTTP_HOST']`, no
 * Grav rootUrl) just as well as under a normal web request.
 */
final class HostBaseResolverTest extends TestCase
{
    public function testConfiguredCanonicalAlwaysWins(): void
    {
        // Even when Grav's rootUrl AND $_SERVER are populated, the
        // operator-set canonical_host is the source of truth.
        self::assertSame(
            'https://blog.example.com',
            HostBaseResolver::resolve(
                configuredCanonical: 'https://blog.example.com',
                gravRootUrl:         'https://something-else.local',
                serverHttps:         true,
                serverHost:          'yet-another.local',
            ),
        );
    }

    public function testBareHostGetsHttpsAndTrailingSlashStripped(): void
    {
        self::assertSame(
            'https://blog.example.com',
            HostBaseResolver::resolve(
                configuredCanonical: 'blog.example.com/',
                gravRootUrl:         '',
                serverHttps:         false,
                serverHost:          '',
            ),
        );
    }

    public function testCanonicalLowercaseHostPreservesPath(): void
    {
        self::assertSame(
            'https://blog.example.com/grav',
            HostBaseResolver::resolve(
                configuredCanonical: 'https://Blog.Example.COM/grav/',
                gravRootUrl:         '',
                serverHttps:         false,
                serverHost:          '',
            ),
        );
    }

    public function testGravRootUrlIsSecondPriority(): void
    {
        self::assertSame(
            'https://blog.local',
            HostBaseResolver::resolve(
                configuredCanonical: '',
                gravRootUrl:         'https://blog.local/',
                serverHttps:         true,
                serverHost:          'should-be-ignored',
            ),
        );
    }

    public function testServerHostAndSchemeIsThirdPriority(): void
    {
        self::assertSame(
            'https://blog.local',
            HostBaseResolver::resolve(
                configuredCanonical: '',
                gravRootUrl:         '',
                serverHttps:         true,
                serverHost:          'blog.local',
            ),
        );
    }

    public function testServerHostHttpWhenHttpsUnset(): void
    {
        self::assertSame(
            'http://blog.local',
            HostBaseResolver::resolve(
                configuredCanonical: '',
                gravRootUrl:         '',
                serverHttps:         false,
                serverHost:          'blog.local',
            ),
        );
    }

    public function testCliWithNoConfigFallsBackToLocalhost(): void
    {
        // This is the v0.0.4 bug, fully reproduced in code. The
        // resolver returns *something* so caller code keeps going;
        // PreflightCheck via isPublishable() is the gate that
        // refuses to enable the plugin in this state.
        self::assertSame(
            'http://localhost',
            HostBaseResolver::resolve(
                configuredCanonical: '',
                gravRootUrl:         '',
                serverHttps:         false,
                serverHost:          '',
            ),
        );
    }

    public function testEmptyConfiguredCanonicalIsTreatedAsUnset(): void
    {
        self::assertSame(
            'https://blog.local',
            HostBaseResolver::resolve(
                configuredCanonical: '   ',
                gravRootUrl:         'https://blog.local',
                serverHttps:         false,
                serverHost:          '',
            ),
        );
    }

    public function testIsPublishableAcceptsRealHttpsHost(): void
    {
        self::assertTrue(HostBaseResolver::isPublishable('https://www.example.com'));
        self::assertTrue(HostBaseResolver::isPublishable('https://www.example.com/'));
        self::assertTrue(HostBaseResolver::isPublishable('https://blog.local'));
    }

    public function testIsPublishableRejectsLocalhostAndLoopback(): void
    {
        foreach (
            // IPv6 literals MUST be bracketed in URLs per RFC 3986 §3.2.2.
            ['http://localhost', 'https://localhost', 'https://127.0.0.1', 'https://[::1]', 'https://0.0.0.0'] as $bad
        ) {
            self::assertFalse(
                HostBaseResolver::isPublishable($bad),
                "expected '$bad' to be unpublishable"
            );
        }
    }

    public function testIsPublishableRejectsHttp(): void
    {
        self::assertFalse(HostBaseResolver::isPublishable('http://www.example.com'));
    }

    public function testIsPublishableRejectsPrivateIpLiterals(): void
    {
        foreach (
            // RFC 1918, CGNAT, ULA, link-local — the same families
            // Mastodon's PrivateNetworkAddressError check covers.
            ['https://10.0.0.1', 'https://192.168.1.1', 'https://172.16.0.1', 'https://[fe80::1]', 'https://[fc00::1]'] as $bad
        ) {
            self::assertFalse(
                HostBaseResolver::isPublishable($bad),
                "expected '$bad' to be unpublishable"
            );
        }
    }

    public function testIsPublishableRejectsEmpty(): void
    {
        self::assertFalse(HostBaseResolver::isPublishable(''));
    }

    public function testIsPublishableRejectsUnparseable(): void
    {
        self::assertFalse(HostBaseResolver::isPublishable('not a url'));
    }

    public function testIsPublishableRejectsPathSegment(): void
    {
        // ActivityPub + WebFinger require document-root install
        // (ADR-004 A-4). A canonical_host with a path segment
        // diverges from what the rest of the plugin assumes.
        self::assertFalse(HostBaseResolver::isPublishable('https://www.example.com/grav'));
        self::assertFalse(HostBaseResolver::isPublishable('https://www.example.com/sites/blog'));
        // Trailing slash on bare origin is OK — that's just "no path".
        self::assertTrue(HostBaseResolver::isPublishable('https://www.example.com'));
    }

    public function testIsPublishableRejectsPort(): void
    {
        // WebFinger's host comparison strips port; allowing port on
        // canonical_host would mean acct: handles couldn't be matched
        // against the canonical actor URL. Reject across the board.
        self::assertFalse(HostBaseResolver::isPublishable('https://www.example.com:8443'));
    }

    public function testIsPublishableRejectsQueryAndFragment(): void
    {
        self::assertFalse(HostBaseResolver::isPublishable('https://www.example.com?foo=1'));
        self::assertFalse(HostBaseResolver::isPublishable('https://www.example.com#frag'));
    }

    public function testIsPublishableRejectsRawUnicodeHost(): void
    {
        // Raw U-label is a homograph footgun. The operator must publish
        // the A-label (xn--…) form — that's what peers verify against.
        self::assertFalse(HostBaseResolver::isPublishable('https://bücher.example'));
        // The A-label encoding of the same name passes.
        self::assertTrue(HostBaseResolver::isPublishable('https://xn--bcher-kva.example'));
    }
}
