<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards against the v0.0.6 regression: the plugin subscribed only to
 * `onAdminAfterSave`, which the Grav 1.10+ Admin no longer fires for
 * Flex Pages (the default page type since flex-objects shipped). New
 * blog posts saved through Admin never reached the push queue — the
 * single feature that justifies the plugin's existence didn't run.
 *
 * `getSubscribedEvents()` itself can't be unit-tested without
 * booting Grav (the plugin extends `\Grav\Common\Plugin`). This test
 * does the same source-grep dance as `RouterWiringTest`: scans the
 * plugin entry and asserts each load-bearing event is subscribed.
 * Crude but it catches the regression class that v0.0.6 shipped to
 * production.
 */
final class EventWiringTest extends TestCase
{
    /**
     * @return list<array{0:string}>
     */
    public static function requiredEvents(): array
    {
        return [
            // Preflight runs early so we can fail fast on bad config.
            ['onPluginsInitialized'],
            // Router dispatcher needs pages built; content negotiation
            // hooks the resolved page.
            ['onPagesInitialized'],
            ['onPageInitialized'],
            // Page-save broadcast. BOTH events must be wired:
            // - onAdminAfterSave fires for classic Page saves
            //   (legacy admin flow, still used in some setups).
            // - onFlexAfterSave fires for Flex Page saves (the
            //   Grav 1.10+ default — the v0.0.6 production bug
            //   was missing this one and dropping every new post
            //   on the floor).
            ['onAdminAfterSave'],
            ['onFlexAfterSave'],
            // Scheduler tick that drains the push queue.
            ['onSchedulerInitialized'],
        ];
    }

    /**
     * @dataProvider requiredEvents
     */
    public function testEventIsSubscribed(string $event): void
    {
        $source = (string) file_get_contents(
            \dirname(__DIR__, 2) . '/fediverse-publisher.php'
        );
        self::assertNotSame('', $source, 'plugin entry source unreadable');
        self::assertStringContainsString(
            "'{$event}'",
            $source,
            "Event {$event} is not referenced in fediverse-publisher.php at all."
        );
        self::assertMatchesRegularExpression(
            "/'" . preg_quote($event, '/') . "'\s*=>/",
            $source,
            "Event {$event} is referenced but not wired into getSubscribedEvents()."
        );
    }
}
