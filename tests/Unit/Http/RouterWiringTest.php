<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;

/**
 * Guards against the v0.0.3 regression: controllers existed on disk
 * with their own passing unit tests, but the plugin-entry router
 * never registered routes for them. Mastodon hit the endpoints, got
 * Grav's HTML 404 page, and the profile rendered "0 followers"
 * regardless of real state.
 *
 * `buildRouter()` itself can't be unit-tested without booting Grav
 * (it touches `$grav['pages']`, the locator, etc.). This test reaches
 * for the next-best thing: it scans the plugin-entry source and
 * confirms each spec-required route is wired. Crude but reliable —
 * the failure mode it catches is exactly the one the v0.0.3 deploy
 * surfaced.
 */
final class RouterWiringTest extends TestCase
{
    /**
     * @return list<array{0:string,1:string}>
     */
    public static function requiredRoutes(): array
    {
        return [
            ['get',  '/.well-known/webfinger'],
            ['get',  '/.well-known/nodeinfo'],
            ['get',  '/nodeinfo/2.0'],
            ['get',  '/activitypub/actor'],
            ['get',  '/activitypub/outbox'],
            ['get',  '/activitypub/followers'],
            ['get',  '/activitypub/following'],
            ['post', '/activitypub/inbox'],
        ];
    }

    /**
     * @dataProvider requiredRoutes
     */
    public function testRouteIsRegistered(string $verb, string $path): void
    {
        $source = (string) file_get_contents(
            \dirname(__DIR__, 3) . '/fediverse-publisher.php'
        );
        self::assertNotSame('', $source, 'plugin entry source unreadable');

        $needle = "->{$verb}('{$path}',";
        self::assertStringContainsString(
            $needle,
            $source,
            "Route {$verb} {$path} is not registered in buildRouter()."
        );
    }
}
