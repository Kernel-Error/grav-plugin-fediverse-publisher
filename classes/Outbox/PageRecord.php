<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Outbox;

use DateTimeImmutable;

/**
 * Framework-agnostic snapshot of a single federatable Grav page. The
 * outbox/transformer/negotiator operate on this value object so they
 * don't need Grav loaded during tests.
 */
final class PageRecord
{
    /**
     * @param list<string> $mediaImageUrls Absolute URLs of images attached to
     *                                     the page via Grav's media API (files
     *                                     sitting next to the markdown). Used
     *                                     as an `attachment` fallback when the
     *                                     body HTML has no `<img>` tag.
     * @param list<string> $tags           Taxonomy tag names taken from Grav's
     *                                     `taxonomy.tag` frontmatter, one per
     *                                     entry, in the order the operator
     *                                     listed them. Emitted as AS 2.0
     *                                     `Hashtag` objects by the
     *                                     transformer — drives Mastodon
     *                                     hashtag-timeline discovery for
     *                                     non-followers.
     */
    public function __construct(
        public readonly string $route,        // e.g. "/blog/first-post"
        public readonly string $url,          // absolute, e.g. "https://blog.local/blog/first-post"
        public readonly string $title,        // human-readable
        public readonly string $contentHtml,  // rendered HTML body
        public readonly DateTimeImmutable $published,
        public readonly DateTimeImmutable $modified,
        public readonly array $mediaImageUrls = [],
        public readonly array $tags = [],
    ) {
    }

    /**
     * Plain-text length of the rendered body, used to decide
     * `Note` vs `Article` per ADR-004 §2.
     */
    public function charCount(): int
    {
        $text = (string) preg_replace('/<[^>]*>/', '', $this->contentHtml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_strlen(trim($text));
    }

    /**
     * Stable opaque page identifier — SHA-256 of the route, first 16
     * hex chars (ADR-004 §12). Used as the pagination cursor in the
     * outbox.
     */
    public function id(): string
    {
        return substr(hash('sha256', $this->route), 0, 16);
    }
}
