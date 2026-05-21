<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Outbox;

use DateTimeImmutable;
use Grav\Plugin\FediversePublisher\Outbox\ActivityTransformer;
use Grav\Plugin\FediversePublisher\Outbox\PageRecord;
use PHPUnit\Framework\TestCase;

final class ActivityTransformerTest extends TestCase
{
    public function testTransformObjectAsNote(): void
    {
        $object = $this->transformer()->transformObject($this->page(), false);

        self::assertSame('Note', $object['type']);
        self::assertSame('https://blog.local/blog/example', $object['id']);
        self::assertSame('https://blog.local/blog/example', $object['url']);
        self::assertSame('https://blog.local/activitypub/actor', $object['attributedTo']);
        self::assertSame(['https://www.w3.org/ns/activitystreams#Public'], $object['to']);
        self::assertSame(['https://blog.local/activitypub/followers'], $object['cc']);
        self::assertSame('2026-05-01T10:00:00Z', $object['published']);
        self::assertSame('2026-05-02T10:00:00Z', $object['updated']);
        self::assertArrayNotHasKey('name', $object, 'Note must not carry a name field');
    }

    public function testTransformObjectAsArticle(): void
    {
        $object = $this->transformer()->transformObject($this->page(), true);

        self::assertSame('Article', $object['type']);
        self::assertSame('Example', $object['name']);
    }

    public function testTransformCreateWrapsTheObject(): void
    {
        $create = $this->transformer()->transformCreate($this->page(), false);

        self::assertSame('Create', $create['type']);
        self::assertSame('https://blog.local/activitypub/actor', $create['actor']);
        self::assertSame('https://blog.local/blog/example#create-' . \strtotime('2026-05-01T10:00:00Z'), $create['id']);
        self::assertSame(['https://www.w3.org/ns/activitystreams#Public'], $create['to']);

        self::assertArrayHasKey('object', $create);
        self::assertSame('Note', $create['object']['type']);
        self::assertArrayNotHasKey('@context', $create['object'],
            'Inner object must not carry its own @context — Create owns it.');
    }

    public function testCreateIdIsStableAcrossCalls(): void
    {
        $a = $this->transformer()->transformCreate($this->page(), false);
        $b = $this->transformer()->transformCreate($this->page(), false);

        self::assertSame($a['id'], $b['id']);
    }

    public function testJsonRoundtripsCleanly(): void
    {
        $create = $this->transformer()->transformCreate($this->page(), true);
        $json = \json_encode($create, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($json);

        $decoded = \json_decode($json, true);
        self::assertSame($create, $decoded);
    }

    private function transformer(): ActivityTransformer
    {
        return new ActivityTransformer(
            actorUrl:     'https://blog.local/activitypub/actor',
            followersUrl: 'https://blog.local/activitypub/followers',
        );
    }

    private function page(): PageRecord
    {
        return new PageRecord(
            route:       '/blog/example',
            url:         'https://blog.local/blog/example',
            title:       'Example',
            contentHtml: '<p>Hello <strong>world</strong>.</p>',
            published:   new DateTimeImmutable('2026-05-01T10:00:00Z'),
            modified:    new DateTimeImmutable('2026-05-02T10:00:00Z'),
        );
    }
}
