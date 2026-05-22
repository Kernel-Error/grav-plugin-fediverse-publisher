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

    public function testClassifyFederatabilityHappyPath(): void
    {
        $page = $this->fakePage('/blog/foo', published: true, routable: true, content: '<p>hi</p>');
        self::assertSame('ok', PageSaveDiagnostics::classifyFederatability($page, '/blog/**'));
    }

    public function testClassifyFederatabilityRejectsRouteOutsidePrefix(): void
    {
        $page = $this->fakePage('/news/foo', published: true, routable: true, content: '<p>hi</p>');
        self::assertSame('not_under_prefix', PageSaveDiagnostics::classifyFederatability($page, '/blog/**'));
    }

    public function testClassifyFederatabilityRejectsUnpublished(): void
    {
        $page = $this->fakePage('/blog/foo', published: false, routable: true, content: '<p>hi</p>');
        self::assertSame('not_published_or_routable', PageSaveDiagnostics::classifyFederatability($page, '/blog/**'));
    }

    public function testClassifyFederatabilityRejectsNonRoutable(): void
    {
        $page = $this->fakePage('/blog/foo', published: true, routable: false, content: '<p>hi</p>');
        self::assertSame('not_published_or_routable', PageSaveDiagnostics::classifyFederatability($page, '/blog/**'));
    }

    public function testClassifyFederatabilityRejectsListingByChildrenCount(): void
    {
        // Listing page = has children. Grav blog convention:
        // /blog/blog.md sits above /blog/<post>/blog.md.
        $page = new class () {
            public function route(): string
            {
                return '/blog';
            }
            public function published(): bool
            {
                return true;
            }
            public function routable(): bool
            {
                return true;
            }
            public function content(): string
            {
                return '<p>blog index</p>';
            }
            public function children(): object
            {
                return new class () {
                    public function count(): int
                    {
                        return 5;
                    }
                };
            }
        };
        self::assertSame('is_listing', PageSaveDiagnostics::classifyFederatability($page, '/blog/**'));
    }

    public function testClassifyFederatabilityRejectsEmptyContent(): void
    {
        $page = $this->fakePage('/blog/foo', published: true, routable: true, content: '<p>   </p>');
        self::assertSame('empty_content', PageSaveDiagnostics::classifyFederatability($page, '/blog/**'));
    }

    public function testClassifyFederatabilityPrefixOrderPrefersRouteOver(): void
    {
        // If both route AND published would bail, route-not-under-prefix
        // is reported first (cheaper, more informative for the operator).
        $page = $this->fakePage('/news/foo', published: false, routable: true, content: '<p>hi</p>');
        self::assertSame('not_under_prefix', PageSaveDiagnostics::classifyFederatability($page, '/blog/**'));
    }

    public function testNormalisedPrefixStripsGlobSuffix(): void
    {
        self::assertSame('/blog', PageSaveDiagnostics::normalisedPrefix('/blog/**'));
        self::assertSame('/blog', PageSaveDiagnostics::normalisedPrefix('/blog/*'));
        self::assertSame('/blog', PageSaveDiagnostics::normalisedPrefix('/blog/'));
        self::assertSame('/blog', PageSaveDiagnostics::normalisedPrefix('/blog'));
        self::assertSame('', PageSaveDiagnostics::normalisedPrefix(''));
    }

    public function testRouteUnderPrefixMatchesExactAndChildren(): void
    {
        self::assertTrue(PageSaveDiagnostics::routeUnderPrefix('/blog', '/blog'));
        self::assertTrue(PageSaveDiagnostics::routeUnderPrefix('/blog/foo', '/blog'));
        self::assertFalse(PageSaveDiagnostics::routeUnderPrefix('/news/foo', '/blog'));
        // Empty prefix matches everything (filter off)
        self::assertTrue(PageSaveDiagnostics::routeUnderPrefix('/anywhere', ''));
        // Prefix-collision guard: `/blogger` is NOT under `/blog`
        self::assertFalse(PageSaveDiagnostics::routeUnderPrefix('/blogger', '/blog'));
    }

    /**
     * Build a stand-in for Grav Page / Flex PageObject with the
     * minimum surface classifyFederatability uses. Returns an
     * anonymous class so each test gets an isolated instance.
     */
    private function fakePage(string $route, bool $published, bool $routable, string $content): object
    {
        return new class ($route, $published, $routable, $content) {
            public function __construct(
                private readonly string $r,
                private readonly bool $p,
                private readonly bool $rt,
                private readonly string $c,
            ) {
            }
            public function route(): string
            {
                return $this->r;
            }
            public function published(): bool
            {
                return $this->p;
            }
            public function routable(): bool
            {
                return $this->rt;
            }
            public function content(): string
            {
                return $this->c;
            }
            public function children(): object
            {
                return new class () {
                    public function count(): int
                    {
                        return 0;
                    }
                };
            }
        };
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
