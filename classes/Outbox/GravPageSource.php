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
        // v0.0.4: detect listing pages by structure, not by template
        // name or frontmatter content. Real-world blogs name their
        // post files `blog.md` inside per-post directories, which is
        // also the conventional listing-template name — keying on
        // template name alone false-positives every post.
        //
        // A page is a listing iff it has children. The Grav blog
        // skeleton structures the tree as `09.blog/blog.md` (the
        // listing, has children) → `09.blog/<post>/<file>.md`
        // (a post, no children). This is robust against
        // copy-pasted frontmatter like `@self.children`.
        if ($this->hasChildren($page)) {
            return false;
        }
        if (!$this->hasNonEmptyContent($page)) {
            return false;
        }
        return true;
    }

    private function hasChildren(PageInterface $page): bool
    {
        if (!method_exists($page, 'children')) {
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
        if (method_exists($children, 'count')) {
            return $children->count() > 0;
        }
        if (is_iterable($children)) {
            foreach ($children as $_) {
                return true;
            }
        }
        return false;
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
