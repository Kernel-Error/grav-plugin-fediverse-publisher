<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Pages;

/**
 * Grav-side adapter for the page collection. Reads from `$grav['pages']`,
 * filters down to routable + published pages whose route falls under
 * the configured glob prefix, and yields PageRecord snapshots in
 * reverse-chronological order.
 *
 * Glob handling is deliberately simple: we strip a trailing `/**` (or
 * `/*`) from the filter and treat what's left as a path prefix. That
 * covers the 95% case (`/blog/**`, `/posts/**`) without dragging a
 * full glob library in for the v0.1 cut.
 */
final class GravPageSource implements PageSource
{
    public function __construct(
        private readonly Pages $pages,
        private readonly string $pathFilter,
        private readonly string $hostBase,
    ) {
    }

    public function listFederatable(): array
    {
        $records = [];
        $prefix = $this->normalisedPrefix();

        foreach ($this->pages->all() as $page) {
            if (!$page instanceof PageInterface) {
                continue;
            }
            if (!$this->isFederatable($page, $prefix)) {
                continue;
            }
            $records[] = $this->buildRecord($page);
        }

        usort(
            $records,
            static fn (PageRecord $a, PageRecord $b): int
                => $b->published <=> $a->published
        );

        return $records;
    }

    public function findByRoute(string $route): ?PageRecord
    {
        $prefix = $this->normalisedPrefix();

        // Cheap rejection before we ask Grav to resolve the page.
        if ($prefix !== '' && !$this->routeUnderPrefix($route, $prefix)) {
            return null;
        }

        $page = $this->pages->find($route);
        if (!$page instanceof PageInterface) {
            return null;
        }
        if (!$this->isFederatable($page, $prefix)) {
            return null;
        }
        return $this->buildRecord($page);
    }

    /**
     * Strip a trailing `/**` or `/*` so callers can write the natural
     * `/blog/**` form in their config.
     */
    private function normalisedPrefix(): string
    {
        $prefix = $this->pathFilter;
        $prefix = (string) preg_replace('#/\*\*?$#', '', $prefix);
        return rtrim($prefix, '/');
    }

    private function routeUnderPrefix(string $route, string $prefix): bool
    {
        if ($prefix === '') {
            return true;
        }
        return $route === $prefix
            || str_starts_with($route, $prefix . '/');
    }

    private function isFederatable(PageInterface $page, string $prefix): bool
    {
        if (!$page->routable() || !$page->published()) {
            return false;
        }
        $route = (string) $page->route();
        if (!$this->routeUnderPrefix($route, $prefix)) {
            return false;
        }
        // v0.0.3: skip pages that look like blog INDEX pages instead of
        // actual posts. The original v0.0.2 path-filter alone matched
        // both `/blog/<post>` and `/blog` (the listing page itself),
        // which then got federated as a "post" with empty body. We
        // filter on two signals — either is sufficient to flag a page
        // as a listing.
        //
        //  1. Twig template names that conventionally render
        //     collections, not single items (`blog`, `listing`,
        //     `archive`, …).
        //  2. The rendered content body is empty (after HTML strip).
        //     Listing pages typically have only their frontmatter
        //     directives, no actual prose.
        if ($this->isListingTemplate($page)) {
            return false;
        }
        if (!$this->hasNonEmptyContent($page)) {
            return false;
        }
        return true;
    }

    private function isListingTemplate(PageInterface $page): bool
    {
        $template = '';
        if (method_exists($page, 'template')) {
            $template = (string) $page->template();
        }
        $template = strtolower($template);
        // Grav-skeleton conventions: `blog` / `archive` / `listing`
        // are containers, `item` / `default` / `post` are content.
        return \in_array($template, ['blog', 'archive', 'listing', 'collection'], true);
    }

    private function hasNonEmptyContent(PageInterface $page): bool
    {
        $html = '';
        try {
            $html = (string) $page->content();
        } catch (\Throwable) {
            return false;
        }
        $text = (string) preg_replace('/<[^>]*>/', '', $html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($text) !== '';
    }

    private function buildRecord(PageInterface $page): PageRecord
    {
        $route = (string) $page->route();
        $url   = $this->hostBase . $route;

        return new PageRecord(
            route:       $route,
            url:         $url,
            title:       (string) $page->title(),
            contentHtml: (string) $page->content(),
            published:   $this->toUtc((int) $page->date()),
            modified:    $this->toUtc((int) $page->modified()),
        );
    }

    private function toUtc(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
