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
     * Extract image references from the rendered HTML and turn them
     * into AS 2.0 `Document` objects. v0.0.3 takes the simple path:
     * pull every absolute or root-relative `<img src=…>` out of the
     * content, attach in order. Relative paths (just a filename) get
     * skipped — they're not reachable from a federated peer.
     *
     * @return list<array<string, string>>
     */
    private function buildAttachments(PageRecord $page): array
    {
        if ($page->contentHtml === '') {
            return [];
        }
        if (!preg_match_all('#<img\b[^>]*\bsrc=["\']([^"\']+)["\']([^>]*)#i', $page->contentHtml, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($matches as $m) {
            $src  = $m[1];
            $rest = $m[2];
            // Only emit absolutely-resolvable URLs — peers can't reach
            // relative `./images/foo.jpg`.
            if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
                $url = $src;
            } elseif (str_starts_with($src, '/')) {
                // Root-relative: prefix the host derived from $page->url
                $parts = parse_url($page->url);
                if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
                    continue;
                }
                $url = $parts['scheme'] . '://' . $parts['host'] . $src;
            } else {
                continue;
            }
            if (isset($seen[$url])) {
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
        return $out;
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
