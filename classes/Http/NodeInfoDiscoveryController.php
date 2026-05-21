<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Http;

use Grav\Plugin\FediversePublisher\NodeInfo\NodeInfoBuilder;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /.well-known/nodeinfo
 *
 * Pointer document: tells consumers where to fetch the actual schema
 * 2.0 doc. We only advertise 2.0 — older 1.x is dead, newer 2.1 is
 * almost identical and any consumer that wants it should also accept
 * 2.0.
 */
final class NodeInfoDiscoveryController
{
    public function __construct(
        private readonly NodeInfoBuilder $builder,
        private readonly string $hostBase,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->builder->discovery($this->hostBase . '/nodeinfo/2.0');

        return new Response(
            200,
            [
                'Content-Type'  => 'application/json; charset=utf-8',
                'Cache-Control' => 'public, max-age=300',
            ],
            (string) \json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}
