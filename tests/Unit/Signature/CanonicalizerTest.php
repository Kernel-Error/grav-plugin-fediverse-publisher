<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Signature;

use Grav\Plugin\FediversePublisher\Signature\Canonicalizer;
use PHPUnit\Framework\TestCase;

final class CanonicalizerTest extends TestCase
{
    public function testOwnerUrlStripsFragment(): void
    {
        self::assertSame(
            'https://blog.local/activitypub/actor',
            Canonicalizer::ownerUrl('https://blog.local/activitypub/actor#main-key'),
        );
    }

    public function testKeyIdPreservesFragment(): void
    {
        self::assertSame(
            'https://blog.local/activitypub/actor#main-key',
            Canonicalizer::keyId('https://blog.local/activitypub/actor#main-key'),
        );
    }

    public function testHostLowercased(): void
    {
        self::assertSame(
            'https://blog.local/x',
            Canonicalizer::ownerUrl('https://BLOG.LOCAL/x'),
        );
    }

    public function testDefaultPortStripped(): void
    {
        self::assertSame(
            'https://blog.local/x',
            Canonicalizer::ownerUrl('https://blog.local:443/x'),
        );
    }

    public function testNonDefaultPortKept(): void
    {
        self::assertSame(
            'https://blog.local:8443/x',
            Canonicalizer::ownerUrl('https://blog.local:8443/x'),
        );
    }

    public function testTrailingSlashStrippedExceptRoot(): void
    {
        self::assertSame(
            'https://blog.local/users/alice',
            Canonicalizer::ownerUrl('https://blog.local/users/alice/'),
        );
        self::assertSame(
            'https://blog.local/',
            Canonicalizer::ownerUrl('https://blog.local/'),
        );
    }

    public function testHttpRejected(): void
    {
        self::assertSame('', Canonicalizer::ownerUrl('http://blog.local/x'));
        self::assertSame('', Canonicalizer::ownerUrl('ftp://blog.local/x'));
    }

    public function testMalformedReturnsEmpty(): void
    {
        self::assertSame('', Canonicalizer::ownerUrl(''));
        self::assertSame('', Canonicalizer::ownerUrl('not a url'));
    }

    public function testIdnaPunycodeAccepted(): void
    {
        // Already-punycoded host stays as-is.
        self::assertSame(
            'https://xn--mnchen-3ya.de/x',
            Canonicalizer::ownerUrl('https://xn--mnchen-3ya.de/x'),
        );
    }

    public function testQueryStringStripped(): void
    {
        self::assertSame(
            'https://blog.local/x',
            Canonicalizer::ownerUrl('https://blog.local/x?foo=bar'),
        );
    }

    public function testPathCaseSensitivePreserved(): void
    {
        self::assertSame(
            'https://blog.local/Users/Alice',
            Canonicalizer::ownerUrl('https://blog.local/Users/Alice'),
        );
    }
}
