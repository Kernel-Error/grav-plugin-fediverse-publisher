<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Outbox;

use DateTimeImmutable;
use Grav\Plugin\FediversePublisher\Outbox\ActivityTransformer;
use Grav\Plugin\FediversePublisher\Outbox\OutboxBroadcaster;
use Grav\Plugin\FediversePublisher\Outbox\PageRecord;
use Grav\Plugin\FediversePublisher\Push\OutboundQueue;
use Grav\Plugin\FediversePublisher\Storage\FollowerStore;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OutboxBroadcasterTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new FollowerStore($this->pdo))->migrate();
        (new OutboundQueue($this->pdo))->migrate();
    }

    public function testNoFollowersMeansZeroEnqueued(): void
    {
        $count = $this->broadcaster()->broadcast($this->page());
        self::assertSame(0, $count);
        self::assertSame(0, $this->countRows('push_queue'));
    }

    public function testEnqueuesOneRowPerFollower(): void
    {
        $followers = new FollowerStore($this->pdo);
        $followers->upsertPending('https://peer.example/users/alice', 'https://peer.example/users/alice/inbox', null);
        $followers->markAccepted('https://peer.example/users/alice');
        $followers->upsertPending('https://peer2.example/users/bob', 'https://peer2.example/users/bob/inbox', null);
        $followers->markAccepted('https://peer2.example/users/bob');

        $count = $this->broadcaster()->broadcast($this->page());

        self::assertSame(2, $count);
        self::assertSame(2, $this->countRows('push_queue'));
    }

    public function testStaleFollowersAreSkipped(): void
    {
        $followers = new FollowerStore($this->pdo);
        $followers->upsertPending('https://peer.example/users/alice', 'https://peer.example/users/alice/inbox', null);
        $followers->markAccepted('https://peer.example/users/alice');
        // Simulate stale by direct UPDATE — production marks stale only
        // via 410 Gone or 5×404 (ADR-003 R2-2).
        $this->pdo->exec(
            "UPDATE followers SET status = 'stale' WHERE actor_url = 'https://peer.example/users/alice'"
        );

        $count = $this->broadcaster()->broadcast($this->page());
        self::assertSame(0, $count);
    }

    public function testBroadcastIsIdempotentOnDoubleCall(): void
    {
        $followers = new FollowerStore($this->pdo);
        $followers->upsertPending('https://peer.example/users/alice', 'https://peer.example/users/alice/inbox', null);
        $followers->markAccepted('https://peer.example/users/alice');

        $b = $this->broadcaster();
        $b->broadcast($this->page());
        $b->broadcast($this->page());

        // INSERT OR IGNORE → second call doesn't add another row for
        // the same (activity, recipient) pair.
        self::assertSame(1, $this->countRows('push_queue'));
    }

    public function testArticleVsNoteByLength(): void
    {
        $followers = new FollowerStore($this->pdo);
        $followers->upsertPending('https://peer.example/users/alice', 'https://peer.example/users/alice/inbox', null);
        $followers->markAccepted('https://peer.example/users/alice');

        $long = $this->page('<p>' . \str_repeat('lorem ', 500) . '</p>');
        (new OutboxBroadcaster(
            followers:     $followers,
            queue:         new OutboundQueue($this->pdo),
            transformer:   new ActivityTransformer(
                actorUrl:     'https://blog.local/activitypub/actor',
                followersUrl: 'https://blog.local/activitypub/followers',
            ),
            localActorUrl: 'https://blog.local/activitypub/actor',
            noteThreshold: 100,
            log:           new NullLogger(),
        ))->broadcast($long);

        $payload = $this->pdo->query('SELECT payload FROM push_queue LIMIT 1')->fetchColumn();
        $doc = \json_decode((string) $payload, true);
        self::assertSame('Article', $doc['object']['type']);
    }

    private function broadcaster(): OutboxBroadcaster
    {
        return new OutboxBroadcaster(
            followers:     new FollowerStore($this->pdo),
            queue:         new OutboundQueue($this->pdo),
            transformer:   new ActivityTransformer(
                actorUrl:     'https://blog.local/activitypub/actor',
                followersUrl: 'https://blog.local/activitypub/followers',
            ),
            localActorUrl: 'https://blog.local/activitypub/actor',
            noteThreshold: 1000,
            log:           new NullLogger(),
        );
    }

    private function page(string $contentHtml = '<p>hi</p>'): PageRecord
    {
        return new PageRecord(
            route:       '/blog/first-post',
            url:         'https://blog.local/blog/first-post',
            title:       'Hello',
            contentHtml: $contentHtml,
            published:   new DateTimeImmutable('2026-05-21T10:00:00Z'),
            modified:    new DateTimeImmutable('2026-05-21T10:00:00Z'),
        );
    }

    private function countRows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }
}
