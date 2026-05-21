<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Http;

use Grav\Plugin\FediversePublisher\Http\FollowersCollectionController;
use Grav\Plugin\FediversePublisher\Push\OutboundQueue;
use Grav\Plugin\FediversePublisher\Storage\FollowerStore;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;

final class FollowersCollectionControllerTest extends TestCase
{
    private const URL = 'https://blog.local/activitypub/followers';

    private PDO $pdo;
    private FollowerStore $followers;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->followers = new FollowerStore($this->pdo);
        $this->followers->migrate();
        // OutboundQueue migration is unrelated but mirrors how plugin entry
        // wires the two stores side by side — keeping the test setup honest.
        (new OutboundQueue($this->pdo))->migrate();
    }

    public function testEmptyCollectionRendersOrderedCollection(): void
    {
        $response = $this->controller()->handle(new ServerRequest('GET', self::URL));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'application/activity+json; charset=utf-8',
            $response->getHeaderLine('Content-Type')
        );

        $doc = json_decode((string) $response->getBody(), true);
        self::assertSame('OrderedCollection', $doc['type']);
        self::assertSame(self::URL, $doc['id']);
        self::assertSame(0, $doc['totalItems']);
        self::assertSame(self::URL . '?page=true&p=1', $doc['first']);
        self::assertSame(self::URL . '?page=true&p=1', $doc['last']);
    }

    public function testCollectionReportsTotalItemsFromStore(): void
    {
        $this->seedFollowers(5);

        $doc = json_decode(
            (string) $this->controller()->handle(new ServerRequest('GET', self::URL))->getBody(),
            true
        );

        self::assertSame(5, $doc['totalItems']);
        self::assertSame(self::URL . '?page=true&p=1', $doc['last']);
    }

    public function testCollectionLastPointsAtFinalPage(): void
    {
        $this->seedFollowers(45);   // 20+20+5 -> 3 pages

        $doc = json_decode(
            (string) $this->controller()->handle(new ServerRequest('GET', self::URL))->getBody(),
            true
        );

        self::assertSame(45, $doc['totalItems']);
        self::assertSame(self::URL . '?page=true&p=3', $doc['last']);
    }

    public function testPageQueryReturnsOrderedCollectionPage(): void
    {
        $this->seedFollowers(3);

        $response = $this->controller()->handle(
            (new ServerRequest('GET', self::URL))->withQueryParams(['page' => 'true'])
        );
        $doc = json_decode((string) $response->getBody(), true);

        self::assertSame('OrderedCollectionPage', $doc['type']);
        self::assertSame(self::URL . '?page=true&p=1', $doc['id']);
        self::assertSame(self::URL, $doc['partOf']);
        self::assertCount(3, $doc['orderedItems']);
        self::assertSame('https://peer.example/users/u0', $doc['orderedItems'][0]);
    }

    public function testPaginationProvidesPrevAndNext(): void
    {
        $this->seedFollowers(45);

        $page2 = json_decode(
            (string) $this->controller()->handle(
                (new ServerRequest('GET', self::URL))->withQueryParams(['page' => 'true', 'p' => '2'])
            )->getBody(),
            true
        );

        self::assertCount(20, $page2['orderedItems']);
        self::assertSame(self::URL . '?page=true&p=1', $page2['prev']);
        self::assertSame(self::URL . '?page=true&p=3', $page2['next']);
    }

    public function testFirstPageHasNoPrev(): void
    {
        $this->seedFollowers(45);
        $doc = json_decode((string) $this->controller()->handle(
            (new ServerRequest('GET', self::URL))->withQueryParams(['page' => 'true', 'p' => '1'])
        )->getBody(), true);

        self::assertArrayNotHasKey('prev', $doc);
        self::assertArrayHasKey('next', $doc);
    }

    public function testLastPageHasNoNext(): void
    {
        $this->seedFollowers(45);
        $doc = json_decode((string) $this->controller()->handle(
            (new ServerRequest('GET', self::URL))->withQueryParams(['page' => 'true', 'p' => '3'])
        )->getBody(), true);

        self::assertArrayHasKey('prev', $doc);
        self::assertArrayNotHasKey('next', $doc);
    }

    public function testInvalidPageNumberClampsToValidRange(): void
    {
        $this->seedFollowers(5);

        // p=0 → clamped to 1
        $doc0 = json_decode((string) $this->controller()->handle(
            (new ServerRequest('GET', self::URL))->withQueryParams(['page' => 'true', 'p' => '0'])
        )->getBody(), true);
        self::assertSame(self::URL . '?page=true&p=1', $doc0['id']);

        // p=999 → clamped to last page (1, since only 5 followers)
        $doc999 = json_decode((string) $this->controller()->handle(
            (new ServerRequest('GET', self::URL))->withQueryParams(['page' => 'true', 'p' => '999'])
        )->getBody(), true);
        self::assertSame(self::URL . '?page=true&p=1', $doc999['id']);
    }

    public function testEmptyStorePageIsStillWellFormed(): void
    {
        $doc = json_decode((string) $this->controller()->handle(
            (new ServerRequest('GET', self::URL))->withQueryParams(['page' => 'true'])
        )->getBody(), true);

        self::assertSame('OrderedCollectionPage', $doc['type']);
        self::assertSame(0, $doc['totalItems']);
        self::assertSame([], $doc['orderedItems']);
    }

    public function testStaleFollowersAreExcluded(): void
    {
        $this->followers->upsertPending('https://peer.example/users/u0', 'https://peer.example/users/u0/inbox', null);
        $this->followers->upsertPending('https://peer.example/users/u1', 'https://peer.example/users/u1/inbox', null);
        // Mark one as stale via direct UPDATE — there's no public setter
        // for the stale state on FollowerStore yet (ADR-003 R2-2).
        $this->pdo->exec("UPDATE followers SET status = 'stale' WHERE actor_url = 'https://peer.example/users/u1'");

        $doc = json_decode((string) $this->controller()->handle(
            new ServerRequest('GET', self::URL)
        )->getBody(), true);

        self::assertSame(1, $doc['totalItems']);
    }

    private function controller(): FollowersCollectionController
    {
        return new FollowersCollectionController(
            followers:     $this->followers,
            followersUrl:  self::URL,
        );
    }

    private function seedFollowers(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->followers->upsertPending(
                "https://peer.example/users/u$i",
                "https://peer.example/users/u$i/inbox",
                null
            );
        }
    }
}
