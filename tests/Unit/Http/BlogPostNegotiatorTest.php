<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Http;

use DateTimeImmutable;
use Grav\Plugin\FediversePublisher\Http\BlogPostNegotiator;
use Grav\Plugin\FediversePublisher\Outbox\ActivityTransformer;
use Grav\Plugin\FediversePublisher\Outbox\PageRecord;
use PHPUnit\Framework\TestCase;

final class BlogPostNegotiatorTest extends TestCase
{
    public function testAcceptsActivityJsonHeader(): void
    {
        self::assertTrue($this->negotiator()->acceptsActivityPub('application/activity+json'));
    }

    public function testAcceptsLdJsonWithAsProfile(): void
    {
        $hdr = 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"';
        self::assertTrue($this->negotiator()->acceptsActivityPub($hdr));
    }

    public function testRejectsPlainJson(): void
    {
        self::assertFalse($this->negotiator()->acceptsActivityPub('application/json'));
    }

    public function testRejectsLdJsonWithoutAsProfile(): void
    {
        self::assertFalse($this->negotiator()->acceptsActivityPub('application/ld+json'));
    }

    public function testRejectsHtml(): void
    {
        self::assertFalse($this->negotiator()->acceptsActivityPub('text/html'));
    }

    public function testCaseInsensitive(): void
    {
        self::assertTrue($this->negotiator()->acceptsActivityPub('Application/Activity+JSON'));
    }

    public function testBuildResponseEmitsNoteForShortContent(): void
    {
        $response = $this->negotiator(noteThreshold: 1000)->buildResponse(
            $this->page('<p>Short body.</p>'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/activity+json; charset=utf-8', $response->getHeaderLine('Content-Type'));

        $body = \json_decode((string) $response->getBody(), true);
        self::assertSame('Note', $body['type']);
        self::assertArrayHasKey('@context', $body, 'Bare object responses MUST carry @context');
    }

    public function testBuildResponseEmitsArticleForLongContent(): void
    {
        $long = '<p>' . \str_repeat('lorem ipsum ', 200) . '</p>';
        $response = $this->negotiator(noteThreshold: 100)->buildResponse($this->page($long));

        $body = \json_decode((string) $response->getBody(), true);
        self::assertSame('Article', $body['type']);
        self::assertSame('Example', $body['name']);
    }

    private function negotiator(int $noteThreshold = 1000): BlogPostNegotiator
    {
        return new BlogPostNegotiator(
            transformer:   new ActivityTransformer(
                actorUrl:     'https://blog.local/activitypub/actor',
                followersUrl: 'https://blog.local/activitypub/followers',
            ),
            noteThreshold: $noteThreshold,
        );
    }

    private function page(string $contentHtml): PageRecord
    {
        return new PageRecord(
            route:       '/blog/example',
            url:         'https://blog.local/blog/example',
            title:       'Example',
            contentHtml: $contentHtml,
            published:   new DateTimeImmutable('2026-05-01T10:00:00Z'),
            modified:    new DateTimeImmutable('2026-05-02T10:00:00Z'),
        );
    }
}
