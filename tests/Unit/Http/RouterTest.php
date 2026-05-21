<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Http;

use Grav\Plugin\FediversePublisher\Http\Router;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class RouterTest extends TestCase
{
    public function testGetRouteMatches(): void
    {
        $router = new Router();
        $router->get('/foo', fn () => new Response(200, [], 'hit'));

        $response = $router->dispatch(new ServerRequest('GET', '/foo'));

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('hit', (string) $response->getBody());
    }

    public function testNoMatchReturnsNull(): void
    {
        $router = new Router();
        $router->get('/foo', fn () => new Response(200));

        $response = $router->dispatch(new ServerRequest('GET', '/bar'));
        self::assertNull($response);
    }

    public function testWrongMethodReturns405WithAllowHeader(): void
    {
        $router = new Router();
        $router->get('/foo', fn () => new Response(200));

        $response = $router->dispatch(new ServerRequest('POST', '/foo'));

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->getHeaderLine('Allow'));
    }

    public function testMultipleMethodsOnSamePathReportAllAllowed(): void
    {
        $router = new Router();
        $router->get('/foo', fn () => new Response(200));
        $router->post('/foo', fn () => new Response(201));

        $response = $router->dispatch(new ServerRequest('DELETE', '/foo'));

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(405, $response->getStatusCode());
        self::assertStringContainsString('GET', $response->getHeaderLine('Allow'));
        self::assertStringContainsString('POST', $response->getHeaderLine('Allow'));
    }

    public function testHeadIsAnsweredFromGetWithEmptyBody(): void
    {
        $router = new Router();
        $router->get('/foo', fn () => new Response(200, ['X-Foo' => 'bar'], 'hello world'));

        $response = $router->dispatch(new ServerRequest('HEAD', '/foo'));

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('bar', $response->getHeaderLine('X-Foo'));
        self::assertSame('11', $response->getHeaderLine('Content-Length'));
        self::assertSame('', (string) $response->getBody());
    }

    public function testMethodMatchingIsCaseInsensitive(): void
    {
        $router = new Router();
        $router->get('/foo', fn () => new Response(200));

        $response = $router->dispatch(new ServerRequest('get', '/foo'));
        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
    }
}
