<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Http;

use DateTimeImmutable;
use Grav\Plugin\FediversePublisher\Http\OutboxController;
use Grav\Plugin\FediversePublisher\Outbox\ActivityTransformer;
use Grav\Plugin\FediversePublisher\Outbox\PageRecord;
use Grav\Plugin\FediversePublisher\Outbox\PageSource;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class OutboxControllerTest extends TestCase
{
    public function testEmptyOutboxRendersOrderedCollection(): void
    {
        $controller = $this->controller([]);
        $response = $controller->handle(new ServerRequest('GET', '/activitypub/outbox'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/activity+json; charset=utf-8', $response->getHeaderLine('Content-Type'));

        $doc = json_decode((string) $response->getBody(), true);
        self::assertSame('OrderedCollection', $doc['type']);
        self::assertSame('https://blog.local/activitypub/outbox', $doc['id']);
        self::assertSame(0, $doc['totalItems']);
        self::assertSame('https://blog.local/activitypub/outbox?page=true&p=1', $doc['first']);
    }

    public function testCollectionTotalItemsReflectsSourceSize(): void
    {
        $controller = $this->controller($this->makePages(5));
        $doc = json_decode((string) $controller->handle(new ServerRequest('GET', '/activitypub/outbox'))->getBody(), true);

        self::assertSame(5, $doc['totalItems']);
        self::assertSame('https://blog.local/activitypub/outbox?page=true&p=1', $doc['last']);
    }

    public function testPageQueryParamReturnsOrderedCollectionPage(): void
    {
        $controller = $this->controller($this->makePages(3));
        $response = $controller->handle(
            (new ServerRequest('GET', '/activitypub/outbox'))->withQueryParams(['page' => 'true'])
        );

        $doc = json_decode((string) $response->getBody(), true);
        self::assertSame('OrderedCollectionPage', $doc['type']);
        self::assertSame('https://blog.local/activitypub/outbox?page=true&p=1', $doc['id']);
        self::assertSame('https://blog.local/activitypub/outbox', $doc['partOf']);
        self::assertCount(3, $doc['orderedItems']);
        self::assertSame('Create', $doc['orderedItems'][0]['type']);
    }

    public function testPaginationProvidesPrevAndNext(): void
    {
        $controller = $this->controller($this->makePages(50));   // 3 pages at 20/page

        $page2 = json_decode((string) $controller->handle(
            (new ServerRequest('GET', '/activitypub/outbox'))->withQueryParams(['page' => 'true', 'p' => '2'])
        )->getBody(), true);

        self::assertCount(20, $page2['orderedItems']);
        self::assertSame('https://blog.local/activitypub/outbox?page=true&p=1', $page2['prev']);
        self::assertSame('https://blog.local/activitypub/outbox?page=true&p=3', $page2['next']);
    }

    public function testFirstPageHasNoPrev(): void
    {
        $controller = $this->controller($this->makePages(50));
        $doc = json_decode((string) $controller->handle(
            (new ServerRequest('GET', '/activitypub/outbox'))->withQueryParams(['page' => 'true', 'p' => '1'])
        )->getBody(), true);

        self::assertArrayNotHasKey('prev', $doc);
        self::assertArrayHasKey('next', $doc);
    }

    public function testLastPageHasNoNext(): void
    {
        $controller = $this->controller($this->makePages(50));
        $doc = json_decode((string) $controller->handle(
            (new ServerRequest('GET', '/activitypub/outbox'))->withQueryParams(['page' => 'true', 'p' => '3'])
        )->getBody(), true);

        self::assertArrayHasKey('prev', $doc);
        self::assertArrayNotHasKey('next', $doc);
    }

    public function testInvalidPageNumberClampsToValidRange(): void
    {
        $controller = $this->controller($this->makePages(5));

        // page 0 → coerced to 1
        $doc0 = json_decode((string) $controller->handle(
            (new ServerRequest('GET', '/activitypub/outbox'))->withQueryParams(['page' => 'true', 'p' => '0'])
        )->getBody(), true);
        self::assertSame('https://blog.local/activitypub/outbox?page=true&p=1', $doc0['id']);

        // page 999 → coerced to last page (only 1 page exists for 5 items)
        $doc999 = json_decode((string) $controller->handle(
            (new ServerRequest('GET', '/activitypub/outbox'))->withQueryParams(['page' => 'true', 'p' => '999'])
        )->getBody(), true);
        self::assertSame('https://blog.local/activitypub/outbox?page=true&p=1', $doc999['id']);
    }

    public function testArticleThresholdAppliesPerItem(): void
    {
        $short = $this->page('short', '<p>hi</p>');
        $long  = $this->page('long', '<p>' . str_repeat('lorem ipsum ', 200) . '</p>');

        $controller = $this->controller([$short, $long], noteThreshold: 100);
        $page = json_decode((string) $controller->handle(
            (new ServerRequest('GET', '/activitypub/outbox'))->withQueryParams(['page' => 'true'])
        )->getBody(), true);

        $types = array_map(static fn (array $a): string => $a['object']['type'], $page['orderedItems']);
        self::assertContains('Note', $types);
        self::assertContains('Article', $types);
    }

    /**
     * @param list<PageRecord> $pages
     */
    private function controller(array $pages, int $noteThreshold = 1000): OutboxController
    {
        $source = new class ($pages) implements PageSource {
            /** @param list<PageRecord> $pages */
            public function __construct(private array $pages)
            {
            }
            public function listFederatable(): array
            {
                return $this->pages;
            }
            public function findByRoute(string $route): ?PageRecord
            {
                foreach ($this->pages as $p) {
                    if ($p->route === $route) {
                        return $p;
                    }
                }
                return null;
            }
        };

        return new OutboxController(
            pages:          $source,
            transformer:    new ActivityTransformer(
                actorUrl:     'https://blog.local/activitypub/actor',
                followersUrl: 'https://blog.local/activitypub/followers',
            ),
            outboxUrl:      'https://blog.local/activitypub/outbox',
            noteThreshold:  $noteThreshold,
        );
    }

    /**
     * @return list<PageRecord>
     */
    private function makePages(int $count): array
    {
        $pages = [];
        for ($i = 0; $i < $count; $i++) {
            $pages[] = $this->page("post-$i");
        }
        return $pages;
    }

    private function page(string $slug, string $contentHtml = '<p>body</p>'): PageRecord
    {
        return new PageRecord(
            route:       '/blog/' . $slug,
            url:         'https://blog.local/blog/' . $slug,
            title:       'Post ' . $slug,
            contentHtml: $contentHtml,
            published:   new DateTimeImmutable('2026-05-01T10:00:00Z'),
            modified:    new DateTimeImmutable('2026-05-02T10:00:00Z'),
        );
    }
}
