<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Inbox\Activities;

use Grav\Plugin\FediversePublisher\Signature\Canonicalizer;
use Grav\Plugin\FediversePublisher\Signature\FetchedKey;
use Grav\Plugin\FediversePublisher\Storage\FollowerStore;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Handles `Undo` activities whose inner `object` is a `Follow`.
 *
 * Promoted to v0.1 (was originally v0.2) so we don't accumulate
 * phantom followers — see Codex round-1 finding "Undo Follow should
 * not be deferred if Follow is accepted".
 *
 * Anything that isn't an Undo-of-a-Follow is silently 202'd. Per
 * spec the inbox is permissive about activity types it doesn't act on.
 */
final class UndoFollowHandler
{
    public function __construct(
        private readonly FollowerStore $followers,
        private readonly string $localActorUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $activity Verified `Undo` body.
     */
    public function handle(array $activity, FetchedKey $verifiedSender): ResponseInterface
    {
        $inner = $activity['object'] ?? null;
        if (!\is_array($inner) || ($inner['type'] ?? null) !== 'Follow') {
            return new Response(202, [], '');
        }

        $innerActor = $inner['actor'] ?? null;
        if (!\is_string($innerActor) || $innerActor === '') {
            return new Response(202, [], '');
        }
        if (Canonicalizer::ownerUrl($innerActor) !== $verifiedSender->ownerUrl) {
            // Undo's inner Follow must be the same actor that signed
            // this Undo. Otherwise we'd be letting A revoke B's follow.
            return new Response(202, [], '');
        }

        $innerTarget = $this->extractTarget($inner);
        if ($innerTarget === null
            || Canonicalizer::ownerUrl($innerTarget) !== Canonicalizer::ownerUrl($this->localActorUrl)) {
            return new Response(202, [], '');
        }

        $this->followers->remove($verifiedSender->ownerUrl);
        return new Response(202, [], '');
    }

    /**
     * @param array<string, mixed> $follow
     */
    private function extractTarget(array $follow): ?string
    {
        $object = $follow['object'] ?? null;
        if (\is_string($object) && $object !== '') {
            return $object;
        }
        if (\is_array($object) && isset($object['id']) && \is_string($object['id']) && $object['id'] !== '') {
            return $object['id'];
        }
        return null;
    }
}
