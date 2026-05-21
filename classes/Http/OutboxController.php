<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Http;

use Grav\Plugin\FediversePublisher\Outbox\ActivityTransformer;
use Grav\Plugin\FediversePublisher\Outbox\PageSource;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /activitypub/outbox
 *
 * Two response modes, matching Mastodon's outbox shape:
 *   - no `page` query param → OrderedCollection wrapper with
 *     totalItems + first/last page links
 *   - `?page=true[&p=N]`    → OrderedCollectionPage with up to
 *     PAGE_SIZE Create activities, plus prev/next links
 *
 * Pagination uses simple 1-indexed page numbers (`p=1`, `p=2`, ...).
 * The W3C AP spec leaves the cursor format implementation-defined as
 * long as next/prev are absolute IRIs that work; clients follow URIs,
 * they don't construct them.
 */
final class OutboxController
{
    private const PAGE_SIZE = 20;

    public function __construct(
        private readonly PageSource $pages,
        private readonly ActivityTransformer $transformer,
        private readonly string $outboxUrl,        // canonical URL, no query
        private readonly int $noteThreshold,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $records = $this->pages->listFederatable();
        $total = \count($records);

        $isPageRequest = ($query['page'] ?? null) === 'true'
            || isset($query['p']);

        if (!$isPageRequest) {
            return $this->respondCollection($total);
        }

        $pageNum = $this->parsePageNumber($query['p'] ?? '1');
        $maxPage = $total === 0 ? 1 : (int) \ceil($total / self::PAGE_SIZE);
        $pageNum = \max(1, \min($pageNum, $maxPage));

        $slice = \array_slice(
            $records,
            ($pageNum - 1) * self::PAGE_SIZE,
            self::PAGE_SIZE,
        );

        return $this->respondPage($slice, $pageNum, $maxPage, $total);
    }

    private function respondCollection(int $total): ResponseInterface
    {
        $doc = [
            '@context'   => 'https://www.w3.org/ns/activitystreams',
            'id'         => $this->outboxUrl,
            'type'       => 'OrderedCollection',
            'totalItems' => $total,
            'first'      => $this->outboxUrl . '?page=true&p=1',
            'last'       => $this->outboxUrl . '?page=true&p=' . \max(1, (int) \ceil($total / self::PAGE_SIZE)),
        ];

        return $this->jsonResponse($doc);
    }

    /**
     * @param list<\Grav\Plugin\FediversePublisher\Outbox\PageRecord> $slice
     */
    private function respondPage(array $slice, int $pageNum, int $maxPage, int $total): ResponseInterface
    {
        $items = [];
        foreach ($slice as $record) {
            $isArticle = $record->charCount() > $this->noteThreshold;
            $items[] = $this->transformer->transformCreate($record, $isArticle);
        }

        $doc = [
            '@context'     => 'https://www.w3.org/ns/activitystreams',
            'id'           => $this->outboxUrl . '?page=true&p=' . $pageNum,
            'type'         => 'OrderedCollectionPage',
            'partOf'       => $this->outboxUrl,
            'totalItems'   => $total,
            'orderedItems' => $items,
        ];

        if ($pageNum > 1) {
            $doc['prev'] = $this->outboxUrl . '?page=true&p=' . ($pageNum - 1);
        }
        if ($pageNum < $maxPage) {
            $doc['next'] = $this->outboxUrl . '?page=true&p=' . ($pageNum + 1);
        }

        return $this->jsonResponse($doc);
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function jsonResponse(array $doc): ResponseInterface
    {
        return new Response(
            200,
            [
                'Content-Type'  => 'application/activity+json; charset=utf-8',
                'Cache-Control' => 'no-store, max-age=0',
                'Vary'          => 'Accept',
            ],
            (string) \json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    private function parsePageNumber(mixed $raw): int
    {
        if (!\is_string($raw) && !\is_int($raw)) {
            return 1;
        }
        $n = (int) $raw;
        return $n > 0 ? $n : 1;
    }
}
