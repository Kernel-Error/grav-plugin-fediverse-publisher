<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Signature;

use Grav\Plugin\FediversePublisher\Signature\CryptoVerifier;
use Grav\Plugin\FediversePublisher\Signature\Signer;
use PHPUnit\Framework\TestCase;
use phpseclib3\Crypt\RSA;

/**
 * Signer + CryptoVerifier are symmetric. Best confidence comes from
 * round-tripping: sign with the private key, verify with the public.
 */
final class SignerTest extends TestCase
{
    private string $privatePem = '';
    private string $publicPem  = '';

    protected function setUp(): void
    {
        $private = RSA::createKey(2048);
        $this->privatePem = (string) $private;
        $this->publicPem  = (string) $private->getPublicKey();
    }

    public function testRoundtripsAgainstVerifier(): void
    {
        $signingString = "(request-target): post /inbox\nhost: blog.example\ndate: Thu, 21 May 2026 12:00:00 GMT\ndigest: SHA-256=abc";
        $sigB64 = (new Signer())->sign($signingString, $this->privatePem);
        self::assertTrue((new CryptoVerifier())->verify($signingString, $sigB64, $this->publicPem));
    }

    public function testDifferentInputProducesDifferentSignature(): void
    {
        $signer = new Signer();
        self::assertNotSame(
            $signer->sign('a', $this->privatePem),
            $signer->sign('b', $this->privatePem),
        );
    }

    public function testMalformedPrivateKeyThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Signer())->sign('payload', 'not a pem');
    }
}
