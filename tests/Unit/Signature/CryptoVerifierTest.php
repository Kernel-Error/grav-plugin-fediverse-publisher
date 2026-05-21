<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Signature;

use Grav\Plugin\FediversePublisher\Signature\CryptoVerifier;
use phpseclib3\Crypt\RSA;
use PHPUnit\Framework\TestCase;

final class CryptoVerifierTest extends TestCase
{
    private string $privatePem = '';
    private string $publicPem = '';

    protected function setUp(): void
    {
        $private = RSA::createKey(2048);
        $this->privatePem = (string) $private;
        $this->publicPem  = (string) $private->getPublicKey();
    }

    public function testHappyPath(): void
    {
        $payload = "(request-target): post /inbox\nhost: blog.local\ndate: Thu, 21 May 2026 12:00:00 GMT";
        $signature = $this->sign($payload);

        self::assertTrue((new CryptoVerifier())->verify($payload, $signature, $this->publicPem));
    }

    public function testWrongSignatureRejected(): void
    {
        $signature = $this->sign('some payload');
        self::assertFalse((new CryptoVerifier())->verify('different payload', $signature, $this->publicPem));
    }

    public function testMalformedPemRejected(): void
    {
        $signature = $this->sign('payload');
        self::assertFalse((new CryptoVerifier())->verify('payload', $signature, 'not a pem'));
    }

    public function testInvalidBase64SignatureRejected(): void
    {
        self::assertFalse((new CryptoVerifier())->verify('payload', '!!!not base64!!!', $this->publicPem));
    }

    public function testEmptyInputsRejected(): void
    {
        self::assertFalse((new CryptoVerifier())->verify('', '', ''));
    }

    private function sign(string $payload): string
    {
        /** @var \phpseclib3\Crypt\RSA\PrivateKey $key */
        $key = RSA::loadPrivateKey($this->privatePem);
        $key = $key->withHash('sha256')->withPadding(RSA::SIGNATURE_PKCS1);
        return base64_encode((string) $key->sign($payload));
    }
}
