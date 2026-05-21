<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Outbox;

use DateTimeImmutable;
use Grav\Plugin\FediversePublisher\Outbox\PageRecord;
use PHPUnit\Framework\TestCase;

final class PageRecordTest extends TestCase
{
    public function testIdIsStable16HexFromRoute(): void
    {
        $a = $this->make(route: '/blog/post-1');
        $b = $this->make(route: '/blog/post-1');

        self::assertSame(16, \strlen($a->id()));
        self::assertSame($a->id(), $b->id(), 'same route → same id');
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $a->id());
    }

    public function testDifferentRoutesProduceDifferentIds(): void
    {
        $a = $this->make(route: '/blog/post-1');
        $b = $this->make(route: '/blog/post-2');

        self::assertNotSame($a->id(), $b->id());
    }

    public function testCharCountStripsHtml(): void
    {
        $page = $this->make(contentHtml: '<p>Hello <strong>world</strong>.</p>');
        self::assertSame(mb_strlen('Hello world.'), $page->charCount());
    }

    public function testCharCountDecodesEntities(): void
    {
        $page = $this->make(contentHtml: '<p>caf&eacute; &amp; bar</p>');
        self::assertSame(mb_strlen('café & bar'), $page->charCount());
    }

    public function testCharCountIsZeroOnEmptyContent(): void
    {
        $page = $this->make(contentHtml: '');
        self::assertSame(0, $page->charCount());
    }

    private function make(
        string $route = '/blog/example',
        string $contentHtml = '<p>body</p>',
    ): PageRecord {
        return new PageRecord(
            route:       $route,
            url:         'https://blog.local' . $route,
            title:       'Example',
            contentHtml: $contentHtml,
            published:   new DateTimeImmutable('2026-05-01T10:00:00Z'),
            modified:    new DateTimeImmutable('2026-05-02T10:00:00Z'),
        );
    }
}
