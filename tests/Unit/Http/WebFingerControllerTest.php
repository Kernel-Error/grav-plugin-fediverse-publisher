<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Http;

use Grav\Plugin\FediversePublisher\Actor\ActorBuilder;
use Grav\Plugin\FediversePublisher\Http\WebFingerController;
use Grav\Plugin\FediversePublisher\Keys\KeyStore;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class WebFingerControllerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/fpub-wf-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach ((array) glob($this->tmpDir . '/*') as $f) {
                unlink($f);
            }
            rmdir($this->tmpDir);
        }
    }

    public function testHappyPath(): void
    {
        $controller = $this->controller(['actor' => ['username' => 'blog']]);

        $request = (new ServerRequest('GET', '/.well-known/webfinger'))
            ->withQueryParams(['resource' => 'acct:blog@blog.local']);

        $response = $controller->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/jrd+json; charset=utf-8', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('acct:blog@blog.local', $body['subject']);
        self::assertContains('https://blog.local/activitypub/actor', $body['aliases']);

        $self = $this->findLink($body['links'], 'self');
        self::assertNotNull($self);
        self::assertSame('application/activity+json', $self['type']);
        self::assertSame('https://blog.local/activitypub/actor', $self['href']);
    }

    public function testHostCaseInsensitive(): void
    {
        $controller = $this->controller(['actor' => ['username' => 'blog']]);

        $request = (new ServerRequest('GET', '/.well-known/webfinger'))
            ->withQueryParams(['resource' => 'acct:blog@BLOG.LOCAL']);

        self::assertSame(200, $controller->handle($request)->getStatusCode());
    }

    public function testUnknownUserIs404(): void
    {
        $controller = $this->controller(['actor' => ['username' => 'blog']]);

        $request = (new ServerRequest('GET', '/.well-known/webfinger'))
            ->withQueryParams(['resource' => 'acct:someone-else@blog.local']);

        self::assertSame(404, $controller->handle($request)->getStatusCode());
    }

    public function testWrongHostIs404(): void
    {
        $controller = $this->controller(['actor' => ['username' => 'blog']]);

        $request = (new ServerRequest('GET', '/.well-known/webfinger'))
            ->withQueryParams(['resource' => 'acct:blog@other-host.example']);

        self::assertSame(404, $controller->handle($request)->getStatusCode());
    }

    public function testMissingResourceParamIs400(): void
    {
        $controller = $this->controller(['actor' => ['username' => 'blog']]);
        $request = new ServerRequest('GET', '/.well-known/webfinger');
        self::assertSame(400, $controller->handle($request)->getStatusCode());
    }

    public function testMalformedResourceIs400(): void
    {
        $controller = $this->controller(['actor' => ['username' => 'blog']]);
        $request = (new ServerRequest('GET', '/.well-known/webfinger'))
            ->withQueryParams(['resource' => 'https://blog.local/notanacct']);
        self::assertSame(400, $controller->handle($request)->getStatusCode());
    }

    public function testUnconfiguredActorIs404(): void
    {
        $controller = $this->controller([]);                    // no username

        $request = (new ServerRequest('GET', '/.well-known/webfinger'))
            ->withQueryParams(['resource' => 'acct:blog@blog.local']);

        self::assertSame(404, $controller->handle($request)->getStatusCode());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function controller(array $config): WebFingerController
    {
        $keys    = new KeyStore($this->tmpDir);
        $actor   = new ActorBuilder($keys, 'https://blog.local', $config);
        return new WebFingerController($actor, 'blog.local');
    }

    /**
     * @param array<int, array<string, string>> $links
     * @return array<string, string>|null
     */
    private function findLink(array $links, string $rel): ?array
    {
        foreach ($links as $link) {
            if (($link['rel'] ?? null) === $rel) {
                return $link;
            }
        }
        return null;
    }
}
