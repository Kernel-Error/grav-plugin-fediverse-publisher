<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Http;

use Grav\Plugin\FediversePublisher\Actor\ActorBuilder;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /.well-known/webfinger?resource=acct:<user>@<host>
 *
 * Returns a JRD-JSON document linking the requested account to the
 * local Actor URL. RFC 7033 + ADR-004 §13.
 *
 * Status codes:
 *   - 200 + JRD body         resource matches the configured actor
 *   - 400                    resource is missing or malformed
 *   - 404                    resource is well-formed but unknown
 */
final class WebFingerController
{
    public function __construct(
        private readonly ActorBuilder $actor,
        private readonly string $localHost,           // e.g. "blog.local"
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $resource = (string) ($request->getQueryParams()['resource'] ?? '');
        if ($resource === '') {
            return $this->error(400, 'missing resource parameter');
        }

        $parsed = $this->parseAcct($resource);
        if ($parsed === null) {
            return $this->error(400, 'resource must be of the form acct:user@host');
        }
        [$user, $host] = $parsed;

        if (!$this->actor->isConfigured()) {
            return $this->error(404, 'unknown account');
        }

        // Case-insensitive host match (RFC), case-sensitive local part.
        if (strcasecmp($host, $this->localHost) !== 0) {
            return $this->error(404, 'unknown account');
        }
        if ($user !== $this->actor->username()) {
            return $this->error(404, 'unknown account');
        }

        $actorUrl = $this->actor->actorUrl();
        $profileUrl = rtrim($this->derivedHostBase($actorUrl), '/') . '/';

        $jrd = [
            'subject' => 'acct:' . $user . '@' . $host,
            'aliases' => [$actorUrl],
            'links' => [
                [
                    'rel'  => 'self',
                    'type' => 'application/activity+json',
                    'href' => $actorUrl,
                ],
                [
                    'rel'  => 'http://webfinger.net/rel/profile-page',
                    'type' => 'text/html',
                    'href' => $profileUrl,
                ],
            ],
        ];

        return new Response(
            200,
            [
                'Content-Type'  => 'application/jrd+json; charset=utf-8',
                'Cache-Control' => 'no-store, max-age=0',
                'Vary'          => 'Accept',
            ],
            (string) json_encode($jrd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * @return array{0:string,1:string}|null  [user, host] or null on malformed input
     */
    private function parseAcct(string $resource): ?array
    {
        if (!str_starts_with($resource, 'acct:')) {
            return null;
        }
        $rest = substr($resource, 5);
        $at = strrpos($rest, '@');
        if ($at === false || $at === 0 || $at === \strlen($rest) - 1) {
            return null;
        }
        $user = substr($rest, 0, $at);
        $host = substr($rest, $at + 1);
        return [$user, $host];
    }

    private function derivedHostBase(string $actorUrl): string
    {
        $parts = parse_url($actorUrl);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        $base = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $base .= ':' . $parts['port'];
        }
        return $base;
    }

    private function error(int $status, string $message): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/jrd+json; charset=utf-8'],
            (string) json_encode(['error' => $message]),
        );
    }
}
