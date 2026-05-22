<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Outbox;

use Grav\Plugin\FediversePublisher\Outbox\PageSaveDiagnostics;
use PHPUnit\Framework\TestCase;

/**
 * Closes the v0.0.7 gap: a subscription test isn't a handler test.
 * v0.0.7's diagnostic line fataled on EVERY Admin save in production
 * because `iterator_to_array($event)` is a TypeError against the
 * RocketTheme Event class (ArrayAccess yes, Traversable no), and no
 * unit test exercised the diagnostic-building code at all.
 *
 * This test fuzzes `buildContext` against the object shapes the
 * handler actually sees in the wild — classic Page, Flex PageObject,
 * User, plain stdClass, scalars, null. Each must return a clean
 * array (no fatal).
 */
final class PageSaveDiagnosticsTest extends TestCase
{
    public function testBuildContextWithClassicPageLikeObject(): void
    {
        // Stand-in for Grav\Common\Page\Page — has route(), published(),
        // routable(). The actual class can't be loaded in standalone
        // unit tests because Grav core isn't in the autoload path.
        $page = new class () {
            public function route(): string
            {
                return '/blog/example';
            }
            public function published(): bool
            {
                return true;
            }
            public function routable(): bool
            {
                return true;
            }
        };

        $ctx = PageSaveDiagnostics::buildContext($page, 'flex');

        self::assertSame('/blog/example', $ctx['object_route']);
        self::assertSame('flex', $ctx['event_type']);
        self::assertStringContainsString('class@anonymous', $ctx['object_class']);
    }

    public function testBuildContextWithFlexPageObjectLikeObject(): void
    {
        // Flex PageObject exposes the same surface as classic Page.
        $obj = new class () {
            public function route(): string
            {
                return '/blog/flex-post';
            }
            public function published(): bool
            {
                return true;
            }
            public function routable(): bool
            {
                return true;
            }
            public function getRoute(): string
            {
                return '/should/not/be/preferred';
            }
        };
        $ctx = PageSaveDiagnostics::buildContext($obj, 'flex');

        // `route()` wins over `getRoute()` per the helper's lookup order.
        self::assertSame('/blog/flex-post', $ctx['object_route']);
    }

    public function testBuildContextWithGetRouteOnlyFallback(): void
    {
        // Some Flex implementations expose only getRoute(). Helper
        // falls through to it.
        $obj = new class () {
            public function getRoute(): string
            {
                return '/blog/get-route-only';
            }
        };
        $ctx = PageSaveDiagnostics::buildContext($obj, null);

        self::assertSame('/blog/get-route-only', $ctx['object_route']);
        self::assertNull($ctx['event_type']);
    }

    public function testBuildContextWithNullObject(): void
    {
        $ctx = PageSaveDiagnostics::buildContext(null, null);

        self::assertSame('NULL', $ctx['object_class']);
        self::assertNull($ctx['object_route']);
        self::assertNull($ctx['event_type']);
    }

    public function testBuildContextWithScalarObject(): void
    {
        // The `mixed` type is on purpose — admin events sometimes
        // carry weird payloads. Must not fatal.
        $ctx = PageSaveDiagnostics::buildContext('string-instead-of-object', null);

        self::assertSame('string', $ctx['object_class']);
        self::assertNull($ctx['object_route']);
    }

    public function testBuildContextWithIntegerObject(): void
    {
        $ctx = PageSaveDiagnostics::buildContext(42, null);

        self::assertSame('integer', $ctx['object_class']);
        self::assertNull($ctx['object_route']);
    }

    public function testBuildContextNeverFatalsOnRouteException(): void
    {
        $bad = new class () {
            public function route(): string
            {
                throw new \RuntimeException('boom');
            }
        };
        $ctx = PageSaveDiagnostics::buildContext($bad, null);

        // Exception was swallowed; no route returned, no fatal.
        self::assertNull($ctx['object_route']);
    }

    public function testBuildContextStringifiesScalarEventType(): void
    {
        $ctx = PageSaveDiagnostics::buildContext(null, 123);
        self::assertSame('123', $ctx['event_type']);

        $ctx = PageSaveDiagnostics::buildContext(null, true);
        self::assertSame('1', $ctx['event_type']);
    }

    public function testBuildContextDropsNonScalarEventType(): void
    {
        // Array event-type isn't meaningful; helper drops to null.
        $ctx = PageSaveDiagnostics::buildContext(null, ['foo' => 'bar']);
        self::assertNull($ctx['event_type']);
    }

    public function testLooksLikePageAcceptsBothPageShapes(): void
    {
        $classic = new class () {
            public function route(): string
            {
                return '';
            }
            public function published(): bool
            {
                return false;
            }
            public function routable(): bool
            {
                return false;
            }
        };
        $flex = new class () {
            public function route(): string
            {
                return '';
            }
            public function published(): bool
            {
                return false;
            }
            public function routable(): bool
            {
                return false;
            }
        };

        self::assertTrue(PageSaveDiagnostics::looksLikePage($classic));
        self::assertTrue(PageSaveDiagnostics::looksLikePage($flex));
    }

    public function testLooksLikePageRejectsUserLikeObject(): void
    {
        // User save events fire onAdminAfterSave too. They carry a
        // User object that has no route() — must be rejected so the
        // handler bails before trying to build a PageRecord from it.
        $user = new class () {
            public function get(string $k): mixed
            {
                return null;
            }
            public function save(): void
            {
            }
        };

        self::assertFalse(PageSaveDiagnostics::looksLikePage($user));
    }

    public function testLooksLikePageRejectsNullAndScalars(): void
    {
        self::assertFalse(PageSaveDiagnostics::looksLikePage(null));
        self::assertFalse(PageSaveDiagnostics::looksLikePage('not-an-object'));
        self::assertFalse(PageSaveDiagnostics::looksLikePage(42));
    }

    public function testLooksLikePageRejectsObjectMissingOneMethod(): void
    {
        // route() + published() present but no routable()
        $partial = new class () {
            public function route(): string
            {
                return '';
            }
            public function published(): bool
            {
                return false;
            }
        };

        self::assertFalse(PageSaveDiagnostics::looksLikePage($partial));
    }
}
