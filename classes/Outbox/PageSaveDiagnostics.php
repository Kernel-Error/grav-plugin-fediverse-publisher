<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Outbox;

/**
 * Pure helpers for the page-save diagnostic log line. Carved out of
 * the plugin-entry handler in v0.0.8 because that handler is
 * un-unit-testable (extends Grav\Common\Plugin, needs the singleton
 * to construct) and the v0.0.7 deploy shipped a fatal in the
 * diagnostic itself — `iterator_to_array(RocketTheme\Toolbox\Event\
 * Event)` crashed every Admin save because that class implements
 * ArrayAccess but NOT Traversable. The structural lesson the
 * operator flagged: a subscription test isn't a handler test; the
 * code that runs at handler entry needs its own unit coverage.
 *
 * Everything here is a static pure function — easy to fuzz with a
 * mix of object types, no Grav dependency. The handler does its
 * Grav-side bookkeeping (preflight, broadcaster construction)
 * around this; this just builds the structured log context.
 */
final class PageSaveDiagnostics
{
    /**
     * Build the log context for the "page-save event received"
     * diagnostic. Safe against any $object shape (null, scalar,
     * arbitrary class) — only reads what's reachable without
     * fataling.
     *
     * @return array{object_class:string, object_route:?string, event_type:?string}
     */
    public static function buildContext(mixed $object, mixed $eventType): array
    {
        return [
            'object_class' => \is_object($object) ? \get_class($object) : \gettype($object),
            'object_route' => self::bestEffortRoute($object),
            'event_type'   => self::stringifyOrNull($eventType),
        ];
    }

    /**
     * Best-effort route extraction. Classic Grav `Page` exposes
     * `route()`, Flex `PageObject` exposes the same. Anything else
     * (User, Config, …) returns null and the caller can decide
     * whether the event is even ours to handle.
     */
    public static function bestEffortRoute(mixed $object): ?string
    {
        if (!\is_object($object)) {
            return null;
        }
        foreach (['route', 'getRoute'] as $method) {
            if (method_exists($object, $method)) {
                try {
                    $value = $object->{$method}();
                } catch (\Throwable) {
                    continue;
                }
                if (\is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }
        return null;
    }

    /**
     * Duck-typed page check used at handler entry. Classic
     * `Grav\Common\Page\Page` and `Grav\Common\Flex\Types\Pages\
     * PageObject` both expose the same surface (`route()`,
     * `published()`, `routable()`) but don't share a base class
     * we can instanceof cheaply. `onAdminAfterSave` also fires
     * for User/Config saves where none of these methods exist —
     * that's the case we bail on.
     */
    public static function looksLikePage(mixed $object): bool
    {
        if (!\is_object($object)) {
            return false;
        }
        foreach (['route', 'published', 'routable'] as $m) {
            if (!method_exists($object, $m)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Classify a page's federatability into one of:
     *   'ok'                        — federate it
     *   'not_under_prefix'          — route doesn't match the
     *                                  configured blog filter
     *   'not_published_or_routable' — `published()` or `routable()`
     *                                  returned false
     *   'is_listing'                — page has children (skeleton
     *                                  listing-page heuristic)
     *   'empty_content'             — `content()` strips to empty
     *
     * Pure function, duck-typed against PageInterface — passes any
     * object that exposes `route()`/`published()`/`routable()`/
     * `content()`/`children()`. Test stand-ins use anonymous
     * classes; no Grav dependency.
     */
    public static function classifyFederatability(
        mixed $page,
        string $pathFilter,
    ): string {
        if (!self::looksLikePage($page)) {
            return 'not_under_prefix'; // shouldn't reach here normally
        }
        $prefix = self::normalisedPrefix($pathFilter);
        $route  = self::bestEffortRoute($page) ?? '';
        if (!self::routeUnderPrefix($route, $prefix)) {
            return 'not_under_prefix';
        }
        /** @var object{published():mixed,routable():mixed} $page */
        if (!$page->published() || !$page->routable()) {
            return 'not_published_or_routable';
        }
        if (self::hasChildren($page)) {
            return 'is_listing';
        }
        if (!self::hasNonEmptyContent($page)) {
            return 'empty_content';
        }
        return 'ok';
    }

    public static function normalisedPrefix(string $pathFilter): string
    {
        $prefix = (string) preg_replace('#/\*\*?$#', '', $pathFilter);
        return rtrim($prefix, '/');
    }

    public static function routeUnderPrefix(string $route, string $prefix): bool
    {
        if ($prefix === '') {
            return true;
        }
        return $route === $prefix || str_starts_with($route, $prefix . '/');
    }

    private static function hasChildren(mixed $page): bool
    {
        if (!\is_object($page) || !method_exists($page, 'children')) {
            return false;
        }
        try {
            $children = $page->children();
        } catch (\Throwable) {
            return false;
        }
        if ($children === null) {
            return false;
        }
        if (\is_object($children) && method_exists($children, 'count')) {
            return $children->count() > 0;
        }
        if (is_iterable($children)) {
            foreach ($children as $_) {
                return true;
            }
        }
        return false;
    }

    private static function hasNonEmptyContent(mixed $page): bool
    {
        if (!\is_object($page) || !method_exists($page, 'content')) {
            return false;
        }
        try {
            $html = (string) $page->content();
        } catch (\Throwable) {
            return false;
        }
        $text = (string) preg_replace('/<[^>]*>/', '', $html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($text) !== '';
    }

    private static function stringifyOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (\is_string($value)) {
            return $value;
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }
        return null;
    }
}
