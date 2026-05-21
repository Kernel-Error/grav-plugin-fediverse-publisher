<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Http;

use Grav\Plugin\FediversePublisher\Http\FollowingCollectionController;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class FollowingCollectionControllerTest extends TestCase
{
    private const URL = 'https://blog.local/activitypub/following';

    public function testReturnsEmptyOrderedCollection(): void
    {
        $response = (new FollowingCollectionController(self::URL))
            ->handle(new ServerRequest('GET', self::URL));

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

    public function testPageRequestReturnsEmptyOrderedCollectionPage(): void
    {
        $response = (new FollowingCollectionController(self::URL))->handle(
            (new ServerRequest('GET', self::URL))->withQueryParams(['page' => 'true'])
        );

        $doc = json_decode((string) $response->getBody(), true);
        self::assertSame('OrderedCollectionPage', $doc['type']);
        self::assertSame(self::URL . '?page=true&p=1', $doc['id']);
        self::assertSame(self::URL, $doc['partOf']);
        self::assertSame(0, $doc['totalItems']);
        self::assertSame([], $doc['orderedItems']);
    }

    public function testAnyPaginationParamTriggersPageShape(): void
    {
        // The plugin's own pages use `?page=true&p=N`. Peers sometimes
        // probe just `?p=1` — both must still produce a Page document.
        $response = (new FollowingCollectionController(self::URL))->handle(
            (new ServerRequest('GET', self::URL))->withQueryParams(['p' => '1'])
        );

        $doc = json_decode((string) $response->getBody(), true);
        self::assertSame('OrderedCollectionPage', $doc['type']);
    }
}
