<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Signature;

use Grav\Plugin\FediversePublisher\Signature\SignatureHeader;
use PHPUnit\Framework\TestCase;

final class SignatureHeaderTest extends TestCase
{
    public function testParsesCanonicalHeader(): void
    {
        $h = SignatureHeader::parse(
            'keyId="https://x/u#main-key",algorithm="rsa-sha256",'
            . 'headers="(request-target) host date digest",signature="aGVsbG8="'
        );
        self::assertNotNull($h);
        self::assertSame('https://x/u#main-key', $h->keyId);
        self::assertSame('rsa-sha256', $h->algorithm);
        self::assertSame(['(request-target)', 'host', 'date', 'digest'], $h->headers);
        self::assertSame('aGVsbG8=', $h->signature);
    }

    public function testCaseInsensitiveParamNames(): void
    {
        $h = SignatureHeader::parse(
            'KeyId="https://x/u",ALGORITHM="rsa-sha256",'
            . 'Headers="(request-target) host",Signature="abc="'
        );
        self::assertNotNull($h);
        self::assertSame('rsa-sha256', $h->algorithm);
    }

    public function testAlgorithmLowercased(): void
    {
        $h = SignatureHeader::parse(
            'keyId="https://x/u",algorithm="RSA-SHA256",headers="host",signature="abc="'
        );
        self::assertNotNull($h);
        self::assertSame('rsa-sha256', $h->algorithm);
    }

    public function testMissingPiecesRejected(): void
    {
        self::assertNull(SignatureHeader::parse(''));
        self::assertNull(SignatureHeader::parse('keyId="x"'));
        self::assertNull(SignatureHeader::parse('keyId="x",algorithm="rsa-sha256"'));
    }

    public function testEmptyKeyIdRejected(): void
    {
        self::assertNull(SignatureHeader::parse(
            'keyId="",algorithm="rsa-sha256",headers="host",signature="abc="'
        ));
    }

    public function testEmptyHeadersRejected(): void
    {
        self::assertNull(SignatureHeader::parse(
            'keyId="https://x/u",algorithm="rsa-sha256",headers="",signature="abc="'
        ));
    }
}
