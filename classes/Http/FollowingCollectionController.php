<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /activitypub/following
 *
 * v0.1 doesn't follow anyone (broadcast-only — the local actor is a
 * publisher, not a reader). The endpoint MUST exist anyway because
 * the actor JSON-LD declares it; peers fetch it during profile
 * resolution and a 404 makes Mastodon render "0 following" even though
 * the actor is structurally fine.
 *
 * Always responds with an empty `OrderedCollection`. When multi-actor
 * + following-other-accounts lands (v0.3+), this gets a real
 * implementation analogous to `FollowersCollectionController`.
 */
final class FollowingCollectionController
{
    public function __construct(private readonly string $followingUrl)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $isPageRequest = ($query['page'] ?? null) === 'true' || isset($query['p']);

        $doc = $isPageRequest
            ? [
                '@context'     => 'https://www.w3.org/ns/activitystreams',
                'id'           => $this->followingUrl . '?page=true&p=1',
                'type'         => 'OrderedCollectionPage',
                'partOf'       => $this->followingUrl,
                'totalItems'   => 0,
                'orderedItems' => [],
            ]
            : [
                '@context'   => 'https://www.w3.org/ns/activitystreams',
                'id'         => $this->followingUrl,
                'type'       => 'OrderedCollection',
                'totalItems' => 0,
                'first'      => $this->followingUrl . '?page=true&p=1',
                'last'       => $this->followingUrl . '?page=true&p=1',
            ];

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
}
