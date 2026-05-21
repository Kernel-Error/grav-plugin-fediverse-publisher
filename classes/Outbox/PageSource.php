<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Outbox;

/**
 * Abstraction over the page collection. The Grav adapter implements
 * this against `$grav['pages']`; tests use an in-memory implementation
 * so they don't need Grav loaded.
 */
interface PageSource
{
    /**
     * @return list<PageRecord> Reverse-chronological (newest first).
     */
    public function listFederatable(): array;

    /**
     * Lookup a single federatable page by its Grav route. Returns null
     * if the route doesn't match the configured filter, or if the page
     * isn't published / routable.
     */
    public function findByRoute(string $route): ?PageRecord;
}
