<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Http;

use Grav\Plugin\FediversePublisher\Http\NodeInfoDiscoveryController;
use Grav\Plugin\FediversePublisher\NodeInfo\NodeInfoBuilder;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class NodeInfoDiscoveryControllerTest extends TestCase
{
    public function testReturnsJsonWithLinksArray(): void
    {
        $controller = new NodeInfoDiscoveryController(
            $this->builder(),
            'https://blog.local',
        );

        $response = $controller->handle(new ServerRequest('GET', '/.well-known/nodeinfo'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('public, max-age=300', $response->getHeaderLine('Cache-Control'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('links', $body);
        self::assertCount(1, $body['links']);
        self::assertSame('http://nodeinfo.diaspora.software/ns/schema/2.0', $body['links'][0]['rel']);
        self::assertSame('https://blog.local/nodeinfo/2.0', $body['links'][0]['href']);
    }

    private function builder(): NodeInfoBuilder
    {
        return new NodeInfoBuilder(
            softwareName:    'grav-fediverse-publisher',
            softwareVersion: '0.0.1',
            hostPlatform:    'grav',
            hostVersion:     '2.0.0-rc.3',
            isConfigured:    true,
            nodeName:        'Blog',
            nodeDescription: '',
        );
    }
}
