<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Http;

use Grav\Plugin\FediversePublisher\Http\NodeInfoController;
use Grav\Plugin\FediversePublisher\NodeInfo\NodeInfoBuilder;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class NodeInfoControllerTest extends TestCase
{
    public function testReturnsSchema20Payload(): void
    {
        $controller = new NodeInfoController($this->builder());
        $response = $controller->handle(new ServerRequest('GET', '/nodeinfo/2.0'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            'application/json; profile="http://nodeinfo.diaspora.software/ns/schema/2.0#";',
            $response->getHeaderLine('Content-Type'),
        );

        $body = \json_decode((string) $response->getBody(), true);
        self::assertSame('2.0', $body['version']);
        self::assertSame(['activitypub'], $body['protocols']);
        self::assertSame('grav-fediverse-publisher', $body['software']['name']);
        self::assertSame(1, $body['usage']['users']['total']);
    }

    public function testUserCountFollowsConfigured(): void
    {
        $controller = new NodeInfoController($this->builder(isConfigured: false));
        $body = \json_decode((string) $controller->handle(new ServerRequest('GET', '/nodeinfo/2.0'))->getBody(), true);

        self::assertSame(0, $body['usage']['users']['total']);
    }

    private function builder(bool $isConfigured = true): NodeInfoBuilder
    {
        return new NodeInfoBuilder(
            softwareName:    'grav-fediverse-publisher',
            softwareVersion: '0.0.1',
            hostPlatform:    'grav',
            hostVersion:     '2.0.0-rc.3',
            isConfigured:    $isConfigured,
            nodeName:        'Test Blog',
            nodeDescription: 'A test.',
        );
    }
}
