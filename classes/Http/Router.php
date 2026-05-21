<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Tiny path/method dispatcher for the plugin's synthetic endpoints.
 *
 * Routes are exact-string matched against the URI path. A path that
 * exists for a different HTTP method returns 405 with an `Allow`
 * header. A path that doesn't match anything returns null, signalling
 * the caller to let the host framework continue normally.
 *
 * Per ADR-004 A-1: we run inside Grav's `onPluginsInitialized` hook,
 * dispatch matching paths to controllers, and terminate the request
 * via `$grav->close($response)`. Returning null lets Grav keep going.
 */
final class Router
{
    /** @var array<string, array<string, callable>> method => path => handler */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[strtoupper($method)][$path] = $handler;
    }

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function dispatch(ServerRequestInterface $request): ?ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $path   = $request->getUri()->getPath();

        if (isset($this->routes[$method][$path])) {
            return ($this->routes[$method][$path])($request);
        }

        // HEAD is normally answered by GET handlers, with the body
        // stripped further down the stack. Try GET routes for HEAD
        // requests so we don't accidentally 405 federation probers.
        if ($method === 'HEAD' && isset($this->routes['GET'][$path])) {
            $response = ($this->routes['GET'][$path])($request);
            $length = $response->getBody()->getSize();
            return $response
                ->withBody(new \Nyholm\Psr7\Stream(fopen('php://temp', 'r+') ?: throw new \RuntimeException('cannot open php://temp')))
                ->withHeader('Content-Length', (string) ($length ?? 0));
        }

        // Path exists under a different method → 405 with the methods
        // that ARE registered for this exact path.
        $allowed = [];
        foreach ($this->routes as $m => $paths) {
            if (isset($paths[$path])) {
                $allowed[] = $m;
            }
        }
        if ($allowed !== []) {
            return new Response(
                405,
                ['Allow' => implode(', ', $allowed)],
                '',
            );
        }

        return null;
    }
}
