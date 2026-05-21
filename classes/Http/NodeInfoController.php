<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Http;

use Grav\Plugin\FediversePublisher\NodeInfo\NodeInfoBuilder;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /nodeinfo/2.0
 *
 * Schema-2.0 instance description per the NodeInfo protocol. The
 * Content-Type carries the profile parameter as recommended by the
 * spec, but most consumers will accept plain `application/json`.
 */
final class NodeInfoController
{
    public function __construct(private readonly NodeInfoBuilder $builder)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->builder->nodeInfo20();

        return new Response(
            200,
            [
                'Content-Type'  => 'application/json; profile="http://nodeinfo.diaspora.software/ns/schema/2.0#"; charset=utf-8',
                'Cache-Control' => 'public, max-age=300',
            ],
            (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}
