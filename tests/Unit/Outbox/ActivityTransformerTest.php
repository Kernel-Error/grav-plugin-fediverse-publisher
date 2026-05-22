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
        self::assertSame('https://blog.local/blog/example/activity/create-' . strtotime('2026-05-01T10:00:00Z'), $create['id']);
        self::assertSame(['https://www.w3.org/ns/activitystreams#Public'], $create['to']);

        self::assertArrayHasKey('object', $create);
        self::assertSame('Note', $create['object']['type']);
        self::assertArrayNotHasKey(
            '@context',
            $create['object'],
            'Inner object must not carry its own @context — Create owns it.'
        );
    }

    public function testCreateIdIsStableAcrossCalls(): void
    {
        $a = $this->transformer()->transformCreate($this->page(), false);
        $b = $this->transformer()->transformCreate($this->page(), false);

        self::assertSame($a['id'], $b['id']);
    }

    public function testCreateIdIsFragmentLessAndDistinctFromObjectId(): void
    {
        // Mastodon 4.5.x indexes activity URI and object URI as
        // separate Status rows when they only differ by `#fragment`.
        // The bonn.social production deploy showed this as "5 real
        // posts displayed as 10". Activity id must be a sibling of
        // the object URL, not a fragment of it.
        $create = $this->transformer()->transformCreate($this->page(), false);

        self::assertStringNotContainsString('#', $create['id']);
        self::assertNotSame($create['id'], $create['object']['id']);
        self::assertStringStartsWith($create['object']['id'] . '/', $create['id']);
    }

    public function testHashtagsEmittedAsAsHashtagObjects(): void
    {
        $page = new PageRecord(
            route:       '/blog/example',
            url:         'https://blog.local/blog/example',
            title:       'Example',
            contentHtml: '<p>hi</p>',
            published:   new DateTimeImmutable('2026-05-01T10:00:00Z'),
            modified:    new DateTimeImmutable('2026-05-02T10:00:00Z'),
            tags:        ['Beratung', 'Weiterbildung'],
        );
        $object = $this->transformerWithTagBase('https://blog.local/blog')
            ->transformObject($page, true);

        self::assertArrayHasKey('tag', $object);
        self::assertCount(2, $object['tag']);
        self::assertSame('Hashtag', $object['tag'][0]['type']);
        self::assertSame('#Beratung', $object['tag'][0]['name']);
        self::assertSame('https://blog.local/blog/tag:Beratung', $object['tag'][0]['href']);
        self::assertSame('#Weiterbildung', $object['tag'][1]['name']);
        self::assertSame('https://blog.local/blog/tag:Weiterbildung', $object['tag'][1]['href']);
    }

    public function testHashtagsPreservedOnNotes(): void
    {
        // Hashtag-discovery matters for short Notes too — don't gate
        // on the Article/Note discriminator.
        $page = new PageRecord(
            route:       '/blog/short',
            url:         'https://blog.local/blog/short',
            title:       'Short',
            contentHtml: '<p>hi</p>',
            published:   new DateTimeImmutable('2026-05-01T10:00:00Z'),
            modified:    new DateTimeImmutable('2026-05-02T10:00:00Z'),
            tags:        ['Beratung'],
        );
        $object = $this->transformerWithTagBase('https://blog.local/blog')
            ->transformObject($page, false); // Note, not Article

        self::assertArrayHasKey('tag', $object);
        self::assertSame('Note', $object['type']);
    }

    public function testHashtagWithoutTagBaseUrlOmitsHref(): void
    {
        // Default constructor (no tagBaseUrl) → `tag` array still
        // emitted, but Hashtag entries carry only `type` and `name`.
        // Mastodon still indexes by `name`; `href` is conventional
        // but optional per AS 2.0.
        $page = new PageRecord(
            route:       '/blog/example',
            url:         'https://blog.local/blog/example',
            title:       'Example',
            contentHtml: '<p>hi</p>',
            published:   new DateTimeImmutable('2026-05-01T10:00:00Z'),
            modified:    new DateTimeImmutable('2026-05-02T10:00:00Z'),
            tags:        ['Beratung'],
        );
        $object = $this->transformer()->transformObject($page, true);

        self::assertArrayHasKey('tag', $object);
        self::assertSame('#Beratung', $object['tag'][0]['name']);
        self::assertArrayNotHasKey('href', $object['tag'][0]);
    }

    public function testHashtagsAbsentWhenNoTags(): void
    {
        $object = $this->transformer()->transformObject($this->page(), true);
        self::assertArrayNotHasKey('tag', $object);
    }

    public function testHashtagsSkipEntriesContainingWhitespace(): void
    {
        // Mastodon parses a hashtag up to the first whitespace, so
        // emitting `#Work Life Balance` would index `Work` and leave
        // the rest as literal text. Better to skip than mis-index.
        $page = new PageRecord(
            route:       '/blog/example',
            url:         'https://blog.local/blog/example',
            title:       'Example',
            contentHtml: '<p>hi</p>',
            published:   new DateTimeImmutable('2026-05-01T10:00:00Z'),
            modified:    new DateTimeImmutable('2026-05-02T10:00:00Z'),
            tags:        ['Beratung', 'Work Life Balance', 'Schichtdienst', ''],
        );
        $object = $this->transformerWithTagBase('https://blog.local/blog')
            ->transformObject($page, true);

        self::assertCount(2, $object['tag']);
        $names = array_column($object['tag'], 'name');
        self::assertSame(['#Beratung', '#Schichtdienst'], $names);
    }

    public function testHashtagHrefIsUrlEncoded(): void
    {
        $page = new PageRecord(
            route:       '/blog/example',
            url:         'https://blog.local/blog/example',
            title:       'Example',
            contentHtml: '<p>hi</p>',
            published:   new DateTimeImmutable('2026-05-01T10:00:00Z'),
            modified:    new DateTimeImmutable('2026-05-02T10:00:00Z'),
            tags:        ['Über-Uns'],
        );
        $object = $this->transformerWithTagBase('https://blog.local/blog')
            ->transformObject($page, true);

        self::assertSame('#Über-Uns', $object['tag'][0]['name']);
        self::assertSame('https://blog.local/blog/tag:%C3%9Cber-Uns', $object['tag'][0]['href']);
    }

    private function transformerWithTagBase(string $tagBaseUrl): ActivityTransformer
    {
        return new ActivityTransformer(
            actorUrl:     'https://blog.local/activitypub/actor',
            followersUrl: 'https://blog.local/activitypub/followers',
            tagBaseUrl:   $tagBaseUrl,
        );
    }

    public function testCreateIdRespectsTrailingSlashOnObjectUrl(): void
    {
        // Some Grav route configurations append a trailing slash to
        // page URLs. The activity-id builder must not produce a
        // double-slash like `https://…/blog/foo//activity/create-…`.
        $page = new PageRecord(
            route:       '/blog/example',
            url:         'https://blog.local/blog/example/',
            title:       'Example',
            contentHtml: '<p>hi</p>',
            published:   new DateTimeImmutable('2026-05-01T10:00:00Z'),
            modified:    new DateTimeImmutable('2026-05-02T10:00:00Z'),
        );
        $create = $this->transformer()->transformCreate($page, false);

        self::assertStringNotContainsString('//activity/', $create['id']);
        self::assertStringEndsWith('/activity/create-' . strtotime('2026-05-01T10:00:00Z'), $create['id']);
    }

    public function testJsonRoundtripsCleanly(): void
    {
        $create = $this->transformer()->transformCreate($this->page(), true);
        $json = json_encode($create, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($json);

        $decoded = json_decode($json, true);
        self::assertSame($create, $decoded);
    }

    public function testSummaryDerivesFromFirstParagraph(): void
    {
        $page = $this->pageWith('<p>First paragraph.</p><p>Second paragraph.</p>');
        $object = $this->transformer()->transformObject($page, true);

        self::assertSame('First paragraph.', $object['summary']);
    }

    public function testSummaryStripsHtmlAndDecodesEntities(): void
    {
        $page = $this->pageWith('<p>Hello <strong>&amp; goodbye</strong>.</p>');
        $object = $this->transformer()->transformObject($page, true);

        self::assertSame('Hello & goodbye.', $object['summary']);
    }

    public function testSummaryCapsAtTwoHundredChars(): void
    {
        // 50 × 5 = 250 chars before truncation.
        $long = str_repeat('lorem', 50);
        $page = $this->pageWith('<p>' . $long . '</p>');
        $object = $this->transformer()->transformObject($page, true);

        self::assertArrayHasKey('summary', $object);
        self::assertSame(200, mb_strlen($object['summary']));
        self::assertStringEndsWith('…', $object['summary']);
    }

    public function testSummaryFallsBackToFullBodyWhenNoParagraph(): void
    {
        $page = $this->pageWith('Plain text with no &lt;p&gt; tag.');
        $object = $this->transformer()->transformObject($page, true);

        self::assertSame('Plain text with no <p> tag.', $object['summary']);
    }

    public function testSummaryAbsentForEmptyContent(): void
    {
        $page = $this->pageWith('');
        $object = $this->transformer()->transformObject($page, true);

        self::assertArrayNotHasKey('summary', $object);
    }

    public function testAttachmentExtractsAbsoluteImageUrls(): void
    {
        $page = $this->pageWith(
            '<p>Look:</p><img src="https://cdn.example/a.jpg" alt="A photo">'
            . '<img src="https://cdn.example/b.png">'
        );
        $object = $this->transformer()->transformObject($page, true);

        self::assertArrayHasKey('attachment', $object);
        self::assertCount(2, $object['attachment']);
        self::assertSame('Document', $object['attachment'][0]['type']);
        self::assertSame('image/jpeg', $object['attachment'][0]['mediaType']);
        self::assertSame('https://cdn.example/a.jpg', $object['attachment'][0]['url']);
        self::assertSame('A photo', $object['attachment'][0]['name']);
        self::assertSame('image/png', $object['attachment'][1]['mediaType']);
        self::assertArrayNotHasKey('name', $object['attachment'][1]);
    }

    public function testAttachmentRewritesRootRelativeUrls(): void
    {
        $page = $this->pageWith('<img src="/uploads/x.webp">');
        $object = $this->transformer()->transformObject($page, true);

        self::assertCount(1, $object['attachment']);
        self::assertSame('https://blog.local/uploads/x.webp', $object['attachment'][0]['url']);
        self::assertSame('image/webp', $object['attachment'][0]['mediaType']);
    }

    public function testAttachmentSkipsBareRelativeUrls(): void
    {
        // Peers can't reach `./images/foo.jpg` — drop it instead of
        // emitting a broken Document.
        $page = $this->pageWith('<img src="./images/foo.jpg">');
        $object = $this->transformer()->transformObject($page, true);

        self::assertArrayNotHasKey('attachment', $object);
    }

    public function testAttachmentDeduplicatesIdenticalUrls(): void
    {
        $page = $this->pageWith(
            '<img src="https://cdn.example/a.jpg"><img src="https://cdn.example/a.jpg">'
        );
        $object = $this->transformer()->transformObject($page, true);

        self::assertCount(1, $object['attachment']);
    }

    public function testAttachmentMediaTypeFromExtension(): void
    {
        $page = $this->pageWith(
            '<img src="https://cdn.example/a.gif">'
            . '<img src="https://cdn.example/b.svg">'
            . '<img src="https://cdn.example/c.avif">'
            . '<img src="https://cdn.example/d.bin">'
        );
        $object = $this->transformer()->transformObject($page, true);

        self::assertSame('image/gif', $object['attachment'][0]['mediaType']);
        self::assertSame('image/svg+xml', $object['attachment'][1]['mediaType']);
        self::assertSame('image/avif', $object['attachment'][2]['mediaType']);
        self::assertSame('application/octet-stream', $object['attachment'][3]['mediaType']);
    }

    public function testAttachmentFallsBackToPageMediaWhenBodyHasNoImg(): void
    {
        // v0.0.4: many Grav blogs keep the hero image next to the
        // markdown without embedding an <img> in the body. The
        // PageRecord carries those URLs through; the transformer
        // surfaces them as `attachment` Documents.
        $page = new PageRecord(
            route:          '/blog/example',
            url:            'https://blog.local/blog/example',
            title:          'Example',
            contentHtml:    '<p>No image in body.</p>',
            published:      new DateTimeImmutable('2026-05-01T10:00:00Z'),
            modified:       new DateTimeImmutable('2026-05-02T10:00:00Z'),
            mediaImageUrls: ['https://blog.local/uploads/hero.jpg'],
        );

        $object = $this->transformer()->transformObject($page, true);

        self::assertArrayHasKey('attachment', $object);
        self::assertCount(1, $object['attachment']);
        self::assertSame('https://blog.local/uploads/hero.jpg', $object['attachment'][0]['url']);
        self::assertSame('image/jpeg', $object['attachment'][0]['mediaType']);
    }

    public function testAttachmentMergesBodyAndMediaWithoutDuplicates(): void
    {
        $page = new PageRecord(
            route:          '/blog/example',
            url:            'https://blog.local/blog/example',
            title:          'Example',
            contentHtml:    '<img src="https://cdn.example/shared.jpg">',
            published:      new DateTimeImmutable('2026-05-01T10:00:00Z'),
            modified:       new DateTimeImmutable('2026-05-02T10:00:00Z'),
            mediaImageUrls: [
                'https://cdn.example/shared.jpg', // dup with body
                'https://cdn.example/extra.png',
            ],
        );

        $object = $this->transformer()->transformObject($page, true);

        self::assertCount(2, $object['attachment']);
        // Body-source comes first (preserved order).
        self::assertSame('https://cdn.example/shared.jpg', $object['attachment'][0]['url']);
        self::assertSame('https://cdn.example/extra.png', $object['attachment'][1]['url']);
    }

    public function testUpdatedNeverPredatesPublished(): void
    {
        // GTS rejects activities where `updated < published`; the
        // local E2E surfaced this when Grav's file mtime sat earlier
        // than the `date:` frontmatter (back-/future-dated posts).
        // The transformer clamps `updated` to at least `published`.
        $page = new PageRecord(
            route:       '/blog/back-dated',
            url:         'https://blog.local/blog/back-dated',
            title:       'Back-dated',
            contentHtml: '<p>hi</p>',
            published:   new DateTimeImmutable('2026-05-21T15:00:00Z'),
            modified:    new DateTimeImmutable('2026-05-21T14:46:00Z'),
        );
        $object = $this->transformer()->transformObject($page, false);

        self::assertSame('2026-05-21T15:00:00Z', $object['published']);
        self::assertSame('2026-05-21T15:00:00Z', $object['updated']);
    }

    public function testUpdatedKeptWhenLaterThanPublished(): void
    {
        // The normal case — operator edits a published post — must
        // not be clobbered by the clamp.
        $page = new PageRecord(
            route:       '/blog/edited',
            url:         'https://blog.local/blog/edited',
            title:       'Edited',
            contentHtml: '<p>hi</p>',
            published:   new DateTimeImmutable('2026-05-01T10:00:00Z'),
            modified:    new DateTimeImmutable('2026-05-15T12:34:00Z'),
        );
        $object = $this->transformer()->transformObject($page, false);

        self::assertSame('2026-05-01T10:00:00Z', $object['published']);
        self::assertSame('2026-05-15T12:34:00Z', $object['updated']);
    }

    public function testMediaImageUrlsRespectRootRelativeRewrite(): void
    {
        $page = new PageRecord(
            route:          '/blog/example',
            url:            'https://blog.local/blog/example',
            title:          'Example',
            contentHtml:    '',
            published:      new DateTimeImmutable('2026-05-01T10:00:00Z'),
            modified:       new DateTimeImmutable('2026-05-02T10:00:00Z'),
            mediaImageUrls: ['/user/pages/09.blog/example/hero.png'],
        );

        $object = $this->transformer()->transformObject($page, true);

        self::assertCount(1, $object['attachment']);
        self::assertSame(
            'https://blog.local/user/pages/09.blog/example/hero.png',
            $object['attachment'][0]['url'],
        );
    }

    private function pageWith(string $html): PageRecord
    {
        return new PageRecord(
            route:       '/blog/example',
            url:         'https://blog.local/blog/example',
            title:       'Example',
            contentHtml: $html,
            published:   new DateTimeImmutable('2026-05-01T10:00:00Z'),
            modified:    new DateTimeImmutable('2026-05-02T10:00:00Z'),
        );
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
