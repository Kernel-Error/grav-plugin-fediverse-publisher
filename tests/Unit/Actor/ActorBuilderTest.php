<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Actor;

use Grav\Plugin\FediversePublisher\Actor\ActorBuilder;
use Grav\Plugin\FediversePublisher\Keys\KeyStore;
use PHPUnit\Framework\TestCase;

final class ActorBuilderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/fpub-actor-' . \bin2hex(\random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->tmpDir)) {
            foreach ((array) \glob($this->tmpDir . '/*') as $f) {
                \unlink($f);
            }
            \rmdir($this->tmpDir);
        }
    }

    public function testIsConfiguredFalseWithoutUsername(): void
    {
        $builder = new ActorBuilder($this->keys(), 'https://blog.local', []);
        self::assertFalse($builder->isConfigured());
    }

    public function testIsConfiguredTrueWithUsername(): void
    {
        $builder = new ActorBuilder($this->keys(), 'https://blog.local', [
            'actor' => ['username' => 'blog'],
        ]);
        self::assertTrue($builder->isConfigured());
    }

    public function testBuildContainsMandatoryAsFields(): void
    {
        $doc = $this->builderWithConfig([
            'actor' => ['username' => 'blog', 'name' => 'My Blog'],
        ])->build();

        self::assertSame('Person', $doc['type']);
        self::assertSame('blog', $doc['preferredUsername']);
        self::assertSame('My Blog', $doc['name']);
        self::assertSame('https://blog.local/activitypub/actor', $doc['id']);
        self::assertSame('https://blog.local/activitypub/inbox',  $doc['inbox']);
        self::assertSame('https://blog.local/activitypub/outbox', $doc['outbox']);
        self::assertSame('https://blog.local/activitypub/followers', $doc['followers']);
        self::assertSame('https://blog.local/activitypub/following', $doc['following']);
        self::assertFalse($doc['manuallyApprovesFollowers']);
    }

    public function testBuildIncludesTwoContextEntries(): void
    {
        $doc = $this->builderWithConfig([
            'actor' => ['username' => 'blog'],
        ])->build();

        self::assertSame(
            [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1',
            ],
            $doc['@context']
        );
    }

    public function testPublicKeyBlockShapeMatchesMastodon(): void
    {
        $doc = $this->builderWithConfig([
            'actor' => ['username' => 'blog'],
        ])->build();

        self::assertArrayHasKey('publicKey', $doc);
        self::assertSame('https://blog.local/activitypub/actor#main-key', $doc['publicKey']['id']);
        self::assertSame('https://blog.local/activitypub/actor',          $doc['publicKey']['owner']);
        self::assertStringContainsString('BEGIN PUBLIC KEY', $doc['publicKey']['publicKeyPem']);
    }

    public function testNameDefaultsToUsernameWhenMissing(): void
    {
        $doc = $this->builderWithConfig([
            'actor' => ['username' => 'blog'],
        ])->build();

        self::assertSame('blog', $doc['name']);
    }

    public function testSummaryAndIconAreOmittedWhenEmpty(): void
    {
        $doc = $this->builderWithConfig([
            'actor' => ['username' => 'blog'],
        ])->build();

        self::assertArrayNotHasKey('summary', $doc);
        self::assertArrayNotHasKey('icon', $doc);
        self::assertArrayNotHasKey('image', $doc);
    }

    public function testIconAndImageEmittedAsImageObjects(): void
    {
        $doc = $this->builderWithConfig([
            'actor' => [
                'username'  => 'blog',
                'icon_url'  => 'https://blog.local/avatar.png',
                'image_url' => 'https://blog.local/banner.jpg',
            ],
        ])->build();

        self::assertSame(['type' => 'Image', 'url' => 'https://blog.local/avatar.png'], $doc['icon']);
        self::assertSame(['type' => 'Image', 'url' => 'https://blog.local/banner.jpg'], $doc['image']);
    }

    public function testJsonEncodesWithoutLosingPemNewlines(): void
    {
        $doc = $this->builderWithConfig([
            'actor' => ['username' => 'blog'],
        ])->build();

        $json = \json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($json);

        $decoded = \json_decode($json, true);
        self::assertStringContainsString('BEGIN PUBLIC KEY', $decoded['publicKey']['publicKeyPem']);
        self::assertStringContainsString('END PUBLIC KEY',   $decoded['publicKey']['publicKeyPem']);
    }

    private function keys(): KeyStore
    {
        return new KeyStore($this->tmpDir);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function builderWithConfig(array $config): ActorBuilder
    {
        return new ActorBuilder($this->keys(), 'https://blog.local', $config);
    }
}
