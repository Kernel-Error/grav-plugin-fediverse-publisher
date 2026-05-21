<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Http;

use Grav\Plugin\FediversePublisher\Outbox\ActivityTransformer;
use Grav\Plugin\FediversePublisher\Outbox\PageRecord;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Encapsulates the "is this Accept header asking for AS 2.0?" check
 * plus the response construction for a blog post served as a Note or
 * Article. Framework-agnostic so the Grav-side glue stays a thin
 * adapter that just feeds it strings.
 *
 * Per ADR-004 §2 and amendment A-5:
 *   - request matches `application/activity+json` or AP-profiled
 *     `application/ld+json`; plain `application/json` is NOT treated
 *     as AP
 *   - response Content-Type is always
 *     `application/activity+json; charset=utf-8`
 *
 * The negotiator does NOT do path-filter matching — that's the
 * caller's job (the page-loaded hook in the plugin entry already
 * knows the resolved page).
 */
final class BlogPostNegotiator
{
    public function __construct(
        private readonly ActivityTransformer $transformer,
        private readonly int $noteThreshold,
    ) {
    }

    /**
     * Inspect an `Accept` header and decide whether the caller wants
     * AP-flavoured JSON-LD.
     */
    public function acceptsActivityPub(string $acceptHeader): bool
    {
        if ($acceptHeader === '') {
            return false;
        }

        $lower = \strtolower($acceptHeader);

        if (\str_contains($lower, 'application/activity+json')) {
            return true;
        }

        // application/ld+json is only AP when the profile parameter
        // points to the AS 2.0 namespace.
        if (\str_contains($lower, 'application/ld+json')
            && \str_contains($lower, 'profile="https://www.w3.org/ns/activitystreams"')) {
            return true;
        }

        return false;
    }

    public function buildResponse(PageRecord $page): ResponseInterface
    {
        $isArticle = $page->charCount() > $this->noteThreshold;
        $object = $this->transformer->transformObject($page, $isArticle);

        return new Response(
            200,
            [
                'Content-Type'  => 'application/activity+json; charset=utf-8',
                'Cache-Control' => 'no-store, max-age=0',
                'Vary'          => 'Accept',
            ],
            (string) \json_encode($object, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}
