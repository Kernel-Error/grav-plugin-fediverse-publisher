<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Http;

use Grav\Plugin\FediversePublisher\Storage\FollowerStore;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /activitypub/followers
 *
 * The actor JSON-LD promises this endpoint; ActivityPub Recommendation
 * §4.1 says it MUST be served as an `OrderedCollection`. Mastodon (and
 * most other peers) fetch this during profile resolution. If the URL
 * 404s, the peer's profile-display logic falls back to "0 followers"
 * even when we know about followers locally — and we look broken.
 *
 * Same shape as `OutboxController`:
 *   - no query params → `OrderedCollection` summary
 *   - `?page=true&p=N` → `OrderedCollectionPage` with up to PAGE_SIZE
 *                       follower actor URIs in `orderedItems`
 */
final class FollowersCollectionController
{
    private const PAGE_SIZE = 20;

    public function __construct(
        private readonly FollowerStore $followers,
        private readonly string $followersUrl,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $total = $this->followers->countForCollection();

        $isPageRequest = ($query['page'] ?? null) === 'true' || isset($query['p']);

        if (!$isPageRequest) {
            return $this->respondCollection($total);
        }

        $maxPage = $total === 0 ? 1 : (int) ceil($total / self::PAGE_SIZE);
        $pageNum = $this->parsePageNumber($query['p'] ?? '1');
        $pageNum = max(1, min($pageNum, $maxPage));

        $items = $this->followers->listForCollection(
            self::PAGE_SIZE,
            ($pageNum - 1) * self::PAGE_SIZE,
        );

        return $this->respondPage($items, $pageNum, $maxPage, $total);
    }

    private function respondCollection(int $total): ResponseInterface
    {
        $doc = [
            '@context'   => 'https://www.w3.org/ns/activitystreams',
            'id'         => $this->followersUrl,
            'type'       => 'OrderedCollection',
            'totalItems' => $total,
            'first'      => $this->followersUrl . '?page=true&p=1',
            'last'       => $this->followersUrl . '?page=true&p=' . max(1, (int) ceil($total / self::PAGE_SIZE)),
        ];
        return $this->jsonResponse($doc);
    }

    /**
     * @param list<string> $items
     */
    private function respondPage(array $items, int $pageNum, int $maxPage, int $total): ResponseInterface
    {
        $doc = [
            '@context'     => 'https://www.w3.org/ns/activitystreams',
            'id'           => $this->followersUrl . '?page=true&p=' . $pageNum,
            'type'         => 'OrderedCollectionPage',
            'partOf'       => $this->followersUrl,
            'totalItems'   => $total,
            'orderedItems' => $items,
        ];
        if ($pageNum > 1) {
            $doc['prev'] = $this->followersUrl . '?page=true&p=' . ($pageNum - 1);
        }
        if ($pageNum < $maxPage) {
            $doc['next'] = $this->followersUrl . '?page=true&p=' . ($pageNum + 1);
        }
        return $this->jsonResponse($doc);
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function jsonResponse(array $doc): ResponseInterface
    {
        return new Response(
            200,
            [
                'Content-Type'  => 'application/activity+json; charset=utf-8',
                'Cache-Control' => 'no-store, max-age=0',
                'Vary'          => 'Accept',
            ],
            (string) json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    private function parsePageNumber(mixed $raw): int
    {
        if (!\is_string($raw) && !\is_int($raw)) {
            return 1;
        }
        $n = (int) $raw;
        return $n > 0 ? $n : 1;
    }
}
