<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\NodeInfo;

use Grav\Plugin\FediversePublisher\NodeInfo\NodeInfoBuilder;
use PHPUnit\Framework\TestCase;

final class NodeInfoBuilderTest extends TestCase
{
    public function testDiscoveryReturnsSchema20Pointer(): void
    {
        $doc = $this->builder()->discovery('https://blog.local/nodeinfo/2.0');

        self::assertSame(
            [
                'links' => [
                    [
                        'rel'  => 'http://nodeinfo.diaspora.software/ns/schema/2.0',
                        'href' => 'https://blog.local/nodeinfo/2.0',
                    ],
                ],
            ],
            $doc
        );
    }

    public function testNodeInfo20Shape(): void
    {
        $doc = $this->builder()->nodeInfo20();

        self::assertSame('2.0', $doc['version']);
        self::assertSame('grav-fediverse-publisher', $doc['software']['name']);
        self::assertSame('0.0.1', $doc['software']['version']);
        self::assertSame(['activitypub'], $doc['protocols']);
        self::assertSame(['inbound' => [], 'outbound' => []], $doc['services']);
        self::assertFalse($doc['openRegistrations']);
    }

    public function testUserCountIsOneWhenConfigured(): void
    {
        $doc = $this->builder(isConfigured: true)->nodeInfo20();
        self::assertSame(1, $doc['usage']['users']['total']);
    }

    public function testUserCountIsZeroWhenNotConfigured(): void
    {
        $doc = $this->builder(isConfigured: false)->nodeInfo20();
        self::assertSame(0, $doc['usage']['users']['total']);
    }

    public function testMetadataCarriesNameDescriptionAndHostInfo(): void
    {
        $doc = $this->builder(
            nodeName: 'My Blog',
            nodeDescription: '<p>About me</p>',
        )->nodeInfo20();

        self::assertSame('My Blog', $doc['metadata']['nodeName']);
        self::assertSame('<p>About me</p>', $doc['metadata']['nodeDescription']);
        self::assertSame('grav', $doc['metadata']['host']['platform']);
        self::assertSame('2.0.0-rc.3', $doc['metadata']['host']['version']);
    }

    public function testJsonEncodableWithoutLosingFields(): void
    {
        $doc = $this->builder()->nodeInfo20();
        $json = \json_encode($doc, JSON_UNESCAPED_SLASHES);
        self::assertIsString($json);

        $decoded = \json_decode($json, true);
        self::assertSame($doc, $decoded);
    }

    private function builder(
        bool $isConfigured = true,
        string $nodeName = 'Test Blog',
        string $nodeDescription = 'A test instance.',
    ): NodeInfoBuilder {
        return new NodeInfoBuilder(
            softwareName:    'grav-fediverse-publisher',
            softwareVersion: '0.0.1',
            hostPlatform:    'grav',
            hostVersion:     '2.0.0-rc.3',
            isConfigured:    $isConfigured,
            nodeName:        $nodeName,
            nodeDescription: $nodeDescription,
        );
    }
}
