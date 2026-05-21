<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Signature;

use DateTimeImmutable;
use Grav\Plugin\FediversePublisher\Signature\FetchedKey;
use Grav\Plugin\FediversePublisher\Signature\KeyCache;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * KeyCache stores rows under the canonical keyId (used as the cache
 * key). The resolved owner URL is a column on the same row. Tests
 * exercise both shapes.
 */
final class KeyCacheTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new KeyCache($this->pdo))->migrate();
    }

    public function testMissReturnsNull(): void
    {
        $cache = new KeyCache($this->pdo);
        self::assertNull($cache->lookup('https://x/u#main-key'));
    }

    public function testPutAndLookupRoundtrip(): void
    {
        $cache = new KeyCache($this->pdo);
        $now = new DateTimeImmutable('@1000');
        $cache->putSuccess(new FetchedKey(
            keyId:          'https://x/u#main-key',
            ownerUrl:       'https://x/u',
            pem:            '-----BEGIN PUBLIC KEY-----\nABC\n-----END PUBLIC KEY-----',
            inboxUrl:       'https://x/u/inbox',
            sharedInboxUrl: null,
            fetchedAt:      $now,
        ));

        // Lookup is by the canonical keyId (= cache key).
        $entry = $cache->lookup('https://x/u#main-key');
        self::assertNotNull($entry);
        self::assertSame('https://x/u#main-key', $entry->keyId);
        self::assertSame('https://x/u', $entry->ownerUrl);
        self::assertStringContainsString('BEGIN PUBLIC KEY', (string) $entry->pem);
        self::assertSame(1000, $entry->fetchedAt);
        self::assertNull($entry->lastFailureAt);
    }

    public function testIsFreshSuccessWithinTtl(): void
    {
        $cache = new KeyCache($this->pdo);
        $cache->putSuccess(new FetchedKey(
            keyId:          'https://x/u#k',
            ownerUrl:       'https://x/u',
            pem:            'pem',
            inboxUrl:       'i',
            sharedInboxUrl: null,
            fetchedAt:      new DateTimeImmutable('@1000'),
        ));

        $entry = $cache->lookup('https://x/u#k');
        self::assertNotNull($entry);
        self::assertTrue($entry->isFreshSuccess(1000 + 3600));
        self::assertFalse($entry->isFreshSuccess(1000 + KeyCache::POSITIVE_TTL_SECONDS + 1));
    }

    public function testPutFailureCreatesNegativeEntry(): void
    {
        $cache = new KeyCache($this->pdo);
        $cache->putFailure('https://x/u#main-key', 2000);

        $entry = $cache->lookup('https://x/u#main-key');
        self::assertNotNull($entry);
        self::assertNull($entry->pem);
        self::assertSame(2000, $entry->lastFailureAt);
        self::assertTrue($entry->isInNegativeWindow(2000 + 60));
        self::assertFalse($entry->isInNegativeWindow(2000 + KeyCache::NEGATIVE_TTL_SECONDS + 1));
    }

    public function testFailureOnSameKeyPreservesPriorPem(): void
    {
        $cache = new KeyCache($this->pdo);
        $cache->putSuccess(new FetchedKey(
            keyId:          'https://x/u#k',
            ownerUrl:       'https://x/u',
            pem:            'PEM-DATA',
            inboxUrl:       'i',
            sharedInboxUrl: null,
            fetchedAt:      new DateTimeImmutable('@1000'),
        ));
        $cache->putFailure('https://x/u#k', 2000);

        $entry = $cache->lookup('https://x/u#k');
        self::assertNotNull($entry);
        self::assertSame('PEM-DATA', $entry->pem,
            'a transient failure on the same key must not delete a previously-cached PEM');
        self::assertSame(2000, $entry->lastFailureAt);
    }
}
