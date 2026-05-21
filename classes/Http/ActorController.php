<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Http;

use Grav\Plugin\FediversePublisher\Actor\ActorBuilder;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /activitypub/actor
 *
 * Serves the AS 2.0 Person Actor JSON-LD. The PHP-class + json_encode
 * approach is deliberate per ADR-004 §9: Twig auto-escape and PEM
 * whitespace handling will quietly break federation. We build the
 * structure as a PHP array and let json_encode do the rest.
 *
 * Always-fresh: Cache-Control: no-store. Mastodon refreshes actor docs
 * every 24h regardless, and our `publicKeyPem` could rotate (v1.x).
 */
final class ActorController
{
    public function __construct(private readonly ActorBuilder $actor)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->actor->isConfigured()) {
            // Plugin is enabled but operator hasn't picked a username
            // yet. 404 rather than 200 with a half-actor.
            return new Response(
                404,
                ['Content-Type' => 'application/activity+json; charset=utf-8'],
                (string) json_encode(['error' => 'actor not configured']),
            );
        }

        $doc = $this->actor->build();
        $body = (string) json_encode(
            $doc,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return new Response(
            200,
            [
                'Content-Type'  => 'application/activity+json; charset=utf-8',
                'Cache-Control' => 'no-store, max-age=0',
                'Vary'          => 'Accept',
            ],
            $body,
        );
    }
}
