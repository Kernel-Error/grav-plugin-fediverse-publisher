<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Signature;

use Grav\Plugin\FediversePublisher\Signature\DigestChecker;
use PHPUnit\Framework\TestCase;

final class DigestCheckerTest extends TestCase
{
    public function testValidDigest(): void
    {
        $body   = '{"hello":"world"}';
        $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
        self::assertTrue((new DigestChecker())->matches($body, $digest));
    }

    public function testMismatchRejected(): void
    {
        self::assertFalse((new DigestChecker())->matches(
            '{"a":1}',
            'SHA-256=' . base64_encode(hash('sha256', '{"a":2}', true)),
        ));
    }

    public function testEmptyBodyHasKnownDigest(): void
    {
        $expected = 'SHA-256=47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=';
        self::assertTrue((new DigestChecker())->matches('', $expected));
    }

    public function testCaseInsensitivePrefix(): void
    {
        $body = 'x';
        $digest = 'sha-256=' . base64_encode(hash('sha256', $body, true));
        self::assertTrue((new DigestChecker())->matches($body, $digest));
    }

    public function testUnknownAlgorithmRejected(): void
    {
        $body = 'x';
        $sha512 = 'SHA-512=' . base64_encode(hash('sha512', $body, true));
        self::assertFalse((new DigestChecker())->matches($body, $sha512));
    }

    public function testMalformedHeader(): void
    {
        self::assertFalse((new DigestChecker())->matches('x', ''));
        self::assertFalse((new DigestChecker())->matches('x', 'garbage'));
    }
}
