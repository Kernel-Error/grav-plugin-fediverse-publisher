<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Signature;

use DateTimeImmutable;
use Grav\Plugin\FediversePublisher\Signature\CryptoVerifier;
use Grav\Plugin\FediversePublisher\Signature\FrozenClock;
use Grav\Plugin\FediversePublisher\Signature\RequestSigner;
use Grav\Plugin\FediversePublisher\Signature\SignatureHeader;
use Grav\Plugin\FediversePublisher\Signature\Signer;
use Grav\Plugin\FediversePublisher\Tests\Support\TestLogger;
use Nyholm\Psr7\Factory\Psr17Factory;
use phpseclib3\Crypt\RSA;
use PHPUnit\Framework\TestCase;

final class RequestSignerTest extends TestCase
{
    private string $privatePem = '';
    private string $publicPem  = '';

    protected function setUp(): void
    {
        $key = RSA::createKey(2048);
        $this->privatePem = (string) $key;
        $this->publicPem  = (string) $key->getPublicKey();
    }

    public function testSignedRequestHasExpectedHeaders(): void
    {
        $factory = new Psr17Factory();
        $body    = '{"hello":"world"}';
        $req = $factory->createRequest('POST', 'https://peer.example/users/alice/inbox')
            ->withBody($factory->createStream($body));

        $signed = $this->signer()->sign(
            $req,
            'https://blog.local/activitypub/actor#main-key',
            $this->privatePem,
        );

        self::assertNotSame('', $signed->getHeaderLine('Date'));
        self::assertStringStartsWith('SHA-256=', $signed->getHeaderLine('Digest'));
        self::assertSame('application/activity+json', $signed->getHeaderLine('Content-Type'));
        self::assertSame('peer.example', $signed->getHeaderLine('Host'));
        self::assertStringContainsString('keyId="https://blog.local/activitypub/actor#main-key"', $signed->getHeaderLine('Signature'));
        self::assertStringContainsString('algorithm="rsa-sha256"', $signed->getHeaderLine('Signature'));
    }

    public function testSignatureVerifiesAgainstPublicKey(): void
    {
        $factory = new Psr17Factory();
        $body = '{"foo":"bar"}';
        $req = $factory->createRequest('POST', 'https://peer.example/users/alice/inbox')
            ->withBody($factory->createStream($body));

        $signed = $this->signer()->sign(
            $req,
            'https://blog.local/activitypub/actor#main-key',
            $this->privatePem,
        );

        $parsed = SignatureHeader::parse($signed->getHeaderLine('Signature'));
        self::assertNotNull($parsed);

        // Reconstruct the signing string from the signed request and
        // verify against our public key.
        $lines = [];
        foreach ($parsed->headers as $name) {
            if ($name === '(request-target)') {
                $uri = $signed->getUri();
                $lines[] = '(request-target): post ' . $uri->getPath();
                continue;
            }
            $lines[] = $name . ': ' . $signed->getHeaderLine($name);
        }
        $signingString = implode("\n", $lines);

        self::assertTrue(
            (new CryptoVerifier())->verify($signingString, $parsed->signature, $this->publicPem),
        );
    }

    public function testDigestMatchesBody(): void
    {
        $factory = new Psr17Factory();
        $body = '{"x":1}';
        $req = $factory->createRequest('POST', 'https://peer.example/inbox')
            ->withBody($factory->createStream($body));

        $signed = $this->signer()->sign($req, 'https://blog.local/a#k', $this->privatePem);

        $expectedDigest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
        self::assertSame($expectedDigest, $signed->getHeaderLine('Digest'));
    }

    public function testHostHeaderIsForceSetFromUriEvenIfRequestCarriesAnotherHost(): void
    {
        // v0.0.4 regression guard. If something further up the chain
        // has put a stale or wrong Host header on the request, the
        // signer must overwrite it — otherwise we sign one value and
        // the HTTP client sends another, and Mastodon returns 401.
        $factory = new Psr17Factory();
        $req = $factory->createRequest('POST', 'https://real-peer.example/inbox')
            ->withHeader('Host', 'wrong-host.invalid')
            ->withBody($factory->createStream('{}'));

        $signed = $this->signer()->sign($req, 'https://blog.local/a#k', $this->privatePem);

        self::assertSame('real-peer.example', $signed->getHeaderLine('Host'));
    }

    public function testHostHeaderOmitsDefaultHttpsPort(): void
    {
        $factory = new Psr17Factory();
        $req = $factory->createRequest('POST', 'https://peer.example/inbox')
            ->withBody($factory->createStream('{}'));

        $signed = $this->signer()->sign($req, 'https://blog.local/a#k', $this->privatePem);

        self::assertSame('peer.example', $signed->getHeaderLine('Host'));
    }

    public function testSignerLogsSigningStringForDiagnostics(): void
    {
        $logger = new TestLogger();
        $signer = new RequestSigner(
            new Signer(),
            new FrozenClock(new DateTimeImmutable('2026-05-21T12:00:00Z')),
            $logger,
        );

        $factory = new Psr17Factory();
        $req = $factory->createRequest('POST', 'https://peer.example/inbox')
            ->withBody($factory->createStream('{"a":1}'));

        $signer->sign($req, 'https://blog.local/a#k', $this->privatePem);

        self::assertNotNull($logger->lastDebug);
        $ctx = $logger->lastDebug;
        self::assertSame('https://blog.local/a#k', $ctx['key_id']);
        self::assertStringContainsString('(request-target): post /inbox', $ctx['signing_string']);
        self::assertStringContainsString('host: peer.example', $ctx['signing_string']);
        self::assertStringStartsWith('SHA-256=', $ctx['digest']);
    }

    private function signer(): RequestSigner
    {
        return new RequestSigner(
            new Signer(),
            new FrozenClock(new DateTimeImmutable('2026-05-21T12:00:00Z')),
        );
    }
}
