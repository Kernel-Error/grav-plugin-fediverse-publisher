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
