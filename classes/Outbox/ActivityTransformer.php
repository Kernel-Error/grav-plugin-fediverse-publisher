<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Outbox;

/**
 * Translates PageRecord → AS 2.0. Two shapes:
 *
 *   - `transformObject()`     — bare `Note` or `Article`, used when a
 *                               federated peer hits the blog-post URL
 *                               with an `Accept: application/activity+json`
 *                               header (content negotiation, ADR-004 §2).
 *   - `transformCreate()`     — outbox / push payload: `Create` activity
 *                               wrapping the object.
 *
 * The Note-vs-Article decision is owned by the caller (the negotiator
 * and the outbox controller both apply the configured threshold), so
 * this class stays single-responsibility.
 */
final class ActivityTransformer
{
    public function __construct(
        private readonly string $actorUrl,        // e.g. https://blog.local/activitypub/actor
        private readonly string $followersUrl,    // e.g. https://blog.local/activitypub/followers
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function transformObject(PageRecord $page, bool $asArticle): array
    {
        $type = $asArticle ? 'Article' : 'Note';

        $object = [
            '@context'     => 'https://www.w3.org/ns/activitystreams',
            'id'           => $page->url,
            'type'         => $type,
            'attributedTo' => $this->actorUrl,
            'to'           => ['https://www.w3.org/ns/activitystreams#Public'],
            'cc'           => [$this->followersUrl],
            'published'    => $page->published->format('Y-m-d\TH:i:s\Z'),
            'updated'      => $page->modified->format('Y-m-d\TH:i:s\Z'),
            'url'          => $page->url,
            'content'      => $page->contentHtml,
        ];

        // Mastodon-style Article rendering needs `summary` (used as
        // the post excerpt / card description, ~160 chars) and
        // `attachment` (for the hero image). Without them, Mastodon
        // falls back to OpenGraph parsing, which usually doesn't pick
        // out a sensible thumbnail or excerpt. Both are optional per
        // the AS 2.0 spec but every mainstream peer reaches for them
        // when rendering an Article inline.
        $summary = $this->buildSummary($page);
        if ($summary !== '') {
            $object['summary'] = $summary;
        }

        $attachments = $this->buildAttachments($page);
        if ($attachments !== []) {
            $object['attachment'] = $attachments;
        }

        if ($asArticle) {
            // Mastodon treats `name` on a Note as malformed; only
            // Articles carry a headline.
            $object['name'] = $page->title;
        }

        return $object;
    }

    /**
     * Build a Mastodon-friendly summary — first paragraph of the
     * rendered HTML, stripped to plain text, capped at 200 chars.
     * Mastodon's UI truncates around 160; 200 leaves a little room
     * without growing the payload unnecessarily.
     */
    private function buildSummary(PageRecord $page): string
    {
        $html = $page->contentHtml;
        if ($html === '') {
            return '';
        }
        // Grab the first paragraph if there is one — its prose is
        // usually the most useful excerpt.
        if (preg_match('#<p[^>]*>(.+?)</p>#is', $html, $m)) {
            $text = $m[1];
        } else {
            $text = $html;
        }
        $text = (string) preg_replace('/<[^>]*>/', '', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) > 200) {
            $text = mb_substr($text, 0, 199) . '…';
        }
        return $text;
    }

    /**
     * Build the AS 2.0 `attachment` list. Two sources are combined,
     * in order, with deduplication on the final URL:
     *
     *   1. `<img src=…>` references embedded in the rendered body
     *      HTML — typically the markdown editor's own image
     *      placements.
     *   2. Images attached via Grav's media API (files sitting next
     *      to the markdown). On many Grav blogs the hero image
     *      lives next to the post but isn't referenced from the
     *      body — without this fallback, Mastodon shows the
     *      article card without a thumbnail.
     *
     * Relative `./foo.jpg` style paths are dropped — peers can't
     * resolve them.
     *
     * @return list<array<string, string>>
     */
    private function buildAttachments(PageRecord $page): array
    {
        $out  = [];
        $seen = [];

        $this->appendFromHtml($page, $out, $seen);
        $this->appendFromMedia($page, $out, $seen);

        return $out;
    }

    /**
     * @param list<array<string, string>> $out
     * @param array<string, true>         $seen
     */
    private function appendFromHtml(PageRecord $page, array &$out, array &$seen): void
    {
        if ($page->contentHtml === '') {
            return;
        }
        if (!preg_match_all('#<img\b[^>]*\bsrc=["\']([^"\']+)["\']([^>]*)#i', $page->contentHtml, $matches, PREG_SET_ORDER)) {
            return;
        }
        foreach ($matches as $m) {
            $src  = $m[1];
            $rest = $m[2];
            $url  = $this->normaliseImageUrl($src, $page->url);
            if ($url === null || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $alt = '';
            if (preg_match('#\balt=["\']([^"\']*)["\']#i', $rest, $altMatch)) {
                $alt = $altMatch[1];
            }
            $entry = [
                'type'      => 'Document',
                'mediaType' => $this->mediaTypeFromUrl($url),
                'url'       => $url,
            ];
            if ($alt !== '') {
                $entry['name'] = $alt;
            }
            $out[] = $entry;
        }
    }

    /**
     * @param list<array<string, string>> $out
     * @param array<string, true>         $seen
     */
    private function appendFromMedia(PageRecord $page, array &$out, array &$seen): void
    {
        foreach ($page->mediaImageUrls as $candidate) {
            $url = $this->normaliseImageUrl($candidate, $page->url);
            if ($url === null || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $out[] = [
                'type'      => 'Document',
                'mediaType' => $this->mediaTypeFromUrl($url),
                'url'       => $url,
            ];
        }
    }

    private function normaliseImageUrl(string $src, string $pageUrl): ?string
    {
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            return $src;
        }
        if (str_starts_with($src, '/')) {
            $parts = parse_url($pageUrl);
            if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
                return null;
            }
            return $parts['scheme'] . '://' . $parts['host'] . $src;
        }
        return null;
    }

    private function mediaTypeFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'avif'        => 'image/avif',
            'svg'         => 'image/svg+xml',
            default       => 'application/octet-stream',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function transformCreate(PageRecord $page, bool $asArticle): array
    {
        $object = $this->transformObject($page, $asArticle);
        // The object inside a Create lives within the activity's
        // JSON-LD context, so strip the duplicate @context line.
        unset($object['@context']);

        return [
            '@context'  => 'https://www.w3.org/ns/activitystreams',
            'id'        => $page->url . '#create-' . $page->published->format('U'),
            'type'      => 'Create',
            'actor'     => $this->actorUrl,
            'to'        => ['https://www.w3.org/ns/activitystreams#Public'],
            'cc'        => [$this->followersUrl],
            'published' => $page->published->format('Y-m-d\TH:i:s\Z'),
            'object'    => $object,
        ];
    }
}
