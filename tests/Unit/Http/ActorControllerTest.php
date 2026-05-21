<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Http;

use Grav\Plugin\FediversePublisher\Actor\ActorBuilder;
use Grav\Plugin\FediversePublisher\Http\ActorController;
use Grav\Plugin\FediversePublisher\Keys\KeyStore;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class ActorControllerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/fpub-actorc-' . \bin2hex(\random_bytes(6));
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

    public function testHappyPathReturnsActivityPubJson(): void
    {
        $controller = $this->controller(['actor' => ['username' => 'blog', 'name' => 'My Blog']]);

        $response = $controller->handle(new ServerRequest('GET', '/activitypub/actor'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/activity+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store, max-age=0', $response->getHeaderLine('Cache-Control'));

        $body = \json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('Person', $body['type']);
        self::assertSame('blog', $body['preferredUsername']);
        self::assertSame('https://blog.local/activitypub/actor', $body['id']);
    }

    public function testUnconfiguredReturns404(): void
    {
        $controller = $this->controller([]);
        $response = $controller->handle(new ServerRequest('GET', '/activitypub/actor'));
        self::assertSame(404, $response->getStatusCode());
    }

    public function testPemContainsRealPublicKey(): void
    {
        $controller = $this->controller(['actor' => ['username' => 'blog']]);
        $response = $controller->handle(new ServerRequest('GET', '/activitypub/actor'));

        $body = \json_decode((string) $response->getBody(), true);
        $pem = $body['publicKey']['publicKeyPem'];
        $key = \openssl_pkey_get_public($pem);

        self::assertNotFalse($key, 'PEM in actor JSON must be a valid public key');
        $details = \openssl_pkey_get_details($key);
        self::assertSame(2048, $details['bits']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function controller(array $config): ActorController
    {
        $keys  = new KeyStore($this->tmpDir);
        $actor = new ActorBuilder($keys, 'https://blog.local', $config);
        return new ActorController($actor);
    }
}
