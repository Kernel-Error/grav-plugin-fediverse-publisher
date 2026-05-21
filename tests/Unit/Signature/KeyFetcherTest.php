<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Signature;

use DateTimeImmutable;
use Grav\Plugin\FediversePublisher\Signature\FrozenClock;
use Grav\Plugin\FediversePublisher\Signature\KeyFetcher;
use Grav\Plugin\FediversePublisher\Signature\KeyFetchException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use phpseclib3\Crypt\RSA;
use PHPUnit\Framework\TestCase;

final class KeyFetcherTest extends TestCase
{
    private string $publicPem = '';

    protected function setUp(): void
    {
        $private = RSA::createKey(2048);
        $this->publicPem = (string) $private->getPublicKey();
    }

    public function testHappyPath(): void
    {
        $body = (string) json_encode([
            'id'        => 'https://blog.local/activitypub/actor',
            'inbox'     => 'https://blog.local/activitypub/inbox',
            'publicKey' => [
                'id'           => 'https://blog.local/activitypub/actor#main-key',
                'owner'        => 'https://blog.local/activitypub/actor',
                'publicKeyPem' => $this->publicPem,
            ],
        ]);

        $fetched = $this->fetcher([
            new Response(200, ['Content-Type' => 'application/activity+json'], $body),
        ])->fetch('https://blog.local/activitypub/actor#main-key');

        self::assertSame('https://blog.local/activitypub/actor#main-key', $fetched->keyId);
        self::assertSame('https://blog.local/activitypub/actor', $fetched->ownerUrl);
        self::assertSame('https://blog.local/activitypub/inbox', $fetched->inboxUrl);
    }

    public function testNon443PortRejected(): void
    {
        $this->expectException(KeyFetchException::class);
        $this->expectExceptionMessage('non-443 port');
        $this->fetcher([])->fetch('https://blog.local:8443/actor#k');
    }

    public function testHttpRejectedAtCanonicaliser(): void
    {
        $this->expectException(KeyFetchException::class);
        $this->fetcher([])->fetch('http://blog.local/actor#k');
    }

    public function testReservedIpRejected(): void
    {
        $this->expectException(KeyFetchException::class);
        $this->expectExceptionMessage('reserved');
        $this->fetcherWithIps(['10.0.0.1'], [])->fetch('https://blog.local/actor#k');
    }

    public function testRedirectRejected(): void
    {
        $this->expectException(KeyFetchException::class);
        $this->fetcher([
            new Response(302, ['Location' => 'https://elsewhere/actor']),
        ])->fetch('https://blog.local/actor#k');
    }

    public function testWrongContentTypeRejected(): void
    {
        $this->expectException(KeyFetchException::class);
        $this->expectExceptionMessage('content-type');
        $this->fetcher([
            new Response(200, ['Content-Type' => 'text/plain'], '{}'),
        ])->fetch('https://blog.local/actor#k');
    }

    public function testParameterInjectionContentTypeRejected(): void
    {
        $this->expectException(KeyFetchException::class);
        $this->fetcher([
            new Response(200, ['Content-Type' => 'text/plain; x=application/activity+json'], '{}'),
        ])->fetch('https://blog.local/actor#k');
    }

    public function testKeyIdMismatchRejected(): void
    {
        $body = (string) json_encode([
            'id'    => 'https://blog.local/actor',
            'inbox' => 'https://blog.local/inbox',
            'publicKey' => [
                'id'           => 'https://elsewhere/actor#main-key',     // doesn't match requested keyId
                'owner'        => 'https://elsewhere/actor',
                'publicKeyPem' => $this->publicPem,
            ],
        ]);

        $this->expectException(KeyFetchException::class);
        $this->expectExceptionMessage('publicKey.id');
        $this->fetcher([
            new Response(200, ['Content-Type' => 'application/activity+json'], $body),
        ])->fetch('https://blog.local/actor#main-key');
    }

    public function testNonRsaPemRejected(): void
    {
        $body = (string) json_encode([
            'id'    => 'https://blog.local/actor',
            'inbox' => 'https://blog.local/inbox',
            'publicKey' => [
                'id'           => 'https://blog.local/actor#main-key',
                'owner'        => 'https://blog.local/actor',
                'publicKeyPem' => "-----BEGIN PUBLIC KEY-----\nbroken\n-----END PUBLIC KEY-----",
            ],
        ]);

        $this->expectException(KeyFetchException::class);
        $this->fetcher([
            new Response(200, ['Content-Type' => 'application/activity+json'], $body),
        ])->fetch('https://blog.local/actor#main-key');
    }

    public function testPartialActorDocSynthesisesInbox(): void
    {
        // GoToSocial 0.21 returns this shape when authorized-fetch is
        // on and the request is unsigned: id (= owner) + publicKey
        // block, but no inbox. v0.1 falls back to <owner>/inbox.
        $body = (string) json_encode([
            'id' => 'https://blog.local/actor',
            'publicKey' => [
                'id'           => 'https://blog.local/actor#main-key',
                'owner'        => 'https://blog.local/actor',
                'publicKeyPem' => $this->publicPem,
            ],
        ]);

        $fetched = $this->fetcher([
            new Response(200, ['Content-Type' => 'application/activity+json'], $body),
        ])->fetch('https://blog.local/actor#main-key');

        self::assertSame('https://blog.local/actor', $fetched->ownerUrl);
        self::assertSame('https://blog.local/actor/inbox', $fetched->inboxUrl);
    }

    public function testAllowListPunchesThroughReservedRange(): void
    {
        $body = (string) json_encode([
            'id'        => 'https://blog.local/actor',
            'inbox'     => 'https://blog.local/inbox',
            'publicKey' => [
                'id'           => 'https://blog.local/actor#main-key',
                'owner'        => 'https://blog.local/actor',
                'publicKeyPem' => $this->publicPem,
            ],
        ]);

        // IP is in 10.0.0.0/8 (would normally be rejected), but the
        // allow-list explicitly punches a hole for 10.89.0.0/16.
        $fetched = $this->fetcherWithIps(
            ['10.89.0.23'],
            [new Response(200, ['Content-Type' => 'application/activity+json'], $body)],
            ['10.89.0.0/16'],
        )->fetch('https://blog.local/actor#main-key');

        self::assertSame('https://blog.local/actor', $fetched->ownerUrl);
    }

    public function testAllowListDoesNotBypassOtherReservedRanges(): void
    {
        $this->expectException(KeyFetchException::class);
        $this->expectExceptionMessage('reserved');
        $this->fetcherWithIps(
            ['127.0.0.1'],                          // loopback — NOT in allow-list
            [],
            ['10.89.0.0/16'],
        )->fetch('https://blog.local/actor#main-key');
    }

    /**
     * @param list<Response> $responses
     */
    private function fetcher(array $responses): KeyFetcher
    {
        return $this->fetcherWithIps(['1.2.3.4'], $responses);
    }

    /**
     * @param list<string>   $ips
     * @param list<Response> $responses
     * @param list<string>   $allowCidrs
     */
    private function fetcherWithIps(array $ips, array $responses, array $allowCidrs = []): KeyFetcher
    {
        $mock = new MockHandler($responses);
        $http = new Client(['handler' => HandlerStack::create($mock), 'http_errors' => false]);
        $clock = new FrozenClock(new DateTimeImmutable('2026-05-21T12:00:00Z'));

        return new KeyFetcher($http, $clock, static fn (string $h) => $ips, $allowCidrs);
    }
}
