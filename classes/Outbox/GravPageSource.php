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
        foreach ($this->pages->all() as $page) {
            if (!$page instanceof PageInterface) {
                continue;
            }
            if (PageSaveDiagnostics::classifyFederatability($page, $this->pathFilter) !== 'ok') {
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
        $prefix = PageSaveDiagnostics::normalisedPrefix($this->pathFilter);

        // Cheap rejection before we ask Grav to resolve the page.
        if ($prefix !== '' && !PageSaveDiagnostics::routeUnderPrefix($route, $prefix)) {
            return null;
        }

        $page = $this->pages->find($route);
        if (!$page instanceof PageInterface) {
            return null;
        }
        if (PageSaveDiagnostics::classifyFederatability($page, $this->pathFilter) !== 'ok') {
            return null;
        }
        return $this->buildRecord($page);
    }

    /**
     * Build a PageRecord directly from an already-resolved PageInterface,
     * skipping the `$pages->find($route)` lookup. The page-save event
     * hands us the PageInterface directly — going back through the
     * `$pages` collection at that point hit Grav's stale pre-save
     * cache and returned null, which is what blocked v0.0.8's
     * auto-broadcast for fresh posts even though `listFederatable()`
     * via a subsequent web request DID see the same page (different
     * request, different cache state).
     *
     * Returns one of:
     *   - PageRecord on the happy path
     *   - The string code for whichever filter rejected the page:
     *       'not_under_prefix', 'not_published_or_routable',
     *       'is_listing', 'empty_content'
     * The caller decides how loud to be about a rejection (the
     * page-save handler logs each branch at INFO so future "why
     * didn't my post federate?" questions are a one-grep answer).
     *
     * All three federatability paths (listFederatable, findByRoute,
     * findByPage) now share a single source of truth via
     * PageSaveDiagnostics::classifyFederatability — the v0.0.8
     * production bug was a footprint of having inconsistent logic
     * in two places where the listing-filter behaved differently
     * for fresh-vs-cached pages.
     */
    public function findByPage(PageInterface $page): PageRecord|string
    {
        $verdict = PageSaveDiagnostics::classifyFederatability($page, $this->pathFilter);
        if ($verdict !== 'ok') {
            return $verdict;
        }
        return $this->buildRecord($page);
    }

    private function buildRecord(PageInterface $page): PageRecord
    {
        $route = (string) $page->route();
        $url   = $this->hostBase . $route;

        return new PageRecord(
            route:          $route,
            url:            $url,
            title:          (string) $page->title(),
            contentHtml:    (string) $page->content(),
            published:      $this->toUtc((int) $page->date()),
            modified:       $this->toUtc((int) $page->modified()),
            mediaImageUrls: $this->collectMediaImages($page, $route),
            tags:           $this->collectTags($page),
        );
    }

    /**
     * Extract the `taxonomy.tag` list from the Grav page. Returns a
     * list of plain string tag names; the transformer wraps them into
     * AS 2.0 `Hashtag` objects. Without this, every Grav blog post's
     * categories are dropped on the federation floor — and hashtag
     * indexing is the single biggest amplifier on Mastodon for posts
     * from accounts that nobody actively follows yet.
     *
     * @return list<string>
     */
    private function collectTags(PageInterface $page): array
    {
        if (!method_exists($page, 'taxonomy')) {
            return [];
        }
        try {
            $taxonomy = $page->taxonomy();
        } catch (\Throwable) {
            return [];
        }
        if (!\is_array($taxonomy)) {
            return [];
        }
        $tags = $taxonomy['tag'] ?? null;
        if (!\is_array($tags)) {
            return [];
        }
        $out = [];
        foreach ($tags as $tag) {
            if (!\is_string($tag)) {
                continue;
            }
            $clean = trim($tag);
            if ($clean === '') {
                continue;
            }
            $out[] = $clean;
        }
        return $out;
    }

    /**
     * Enumerate images attached to the page via Grav's media API
     * (files sitting next to the markdown). Returns absolute URLs.
     * Used as a fallback for the AS 2.0 `attachment` field when the
     * body HTML has no `<img src=…>` tag of its own.
     *
     * @return list<string>
     */
    private function collectMediaImages(PageInterface $page, string $route): array
    {
        if (!method_exists($page, 'media')) {
            return [];
        }
        try {
            $media = $page->media();
        } catch (\Throwable) {
            return [];
        }
        if (!is_iterable($media)) {
            return [];
        }

        $urls = [];
        foreach ($media as $filename => $medium) {
            $name = \is_string($filename) ? $filename : '';
            if (!$this->looksLikeImageFilename($name)) {
                continue;
            }
            $url = $this->resolveMediumUrl($medium, $name, $route);
            if ($url !== null) {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    private function looksLikeImageFilename(string $name): bool
    {
        if ($name === '') {
            return false;
        }
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        return \in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'], true);
    }

    private function resolveMediumUrl(mixed $medium, string $filename, string $route): ?string
    {
        // Grav Medium exposes `url()` returning an absolute path
        // (e.g. `/user/pages/09.blog/.../foo.jpg`). Fall back to a
        // derived route-relative URL if the medium doesn't expose
        // anything usable.
        if (\is_object($medium) && method_exists($medium, 'url')) {
            try {
                $candidate = (string) $medium->url();
            } catch (\Throwable) {
                $candidate = '';
            }
            if ($candidate !== '') {
                return $this->makeAbsolute($candidate);
            }
        }
        // Best-effort fallback: assume the file is published at the
        // page's own route. This won't work for every theme but it's
        // strictly better than dropping the attachment entirely.
        return $this->makeAbsolute(rtrim($route, '/') . '/' . $filename);
    }

    private function makeAbsolute(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        if (!str_starts_with($url, '/')) {
            $url = '/' . $url;
        }
        return $this->hostBase . $url;
    }

    private function toUtc(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
