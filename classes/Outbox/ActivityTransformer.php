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

        if ($asArticle) {
            // Mastodon treats `name` on a Note as malformed; only
            // Articles carry a headline.
            $object['name'] = $page->title;
        }

        return $object;
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
