<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Inbox\Activities;

use Grav\Plugin\FediversePublisher\Push\OutboundQueue;
use Grav\Plugin\FediversePublisher\Signature\Canonicalizer;
use Grav\Plugin\FediversePublisher\Signature\FetchedKey;
use Grav\Plugin\FediversePublisher\Storage\FollowerStore;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Accepts an inbound `Follow` activity. Per ADR-004 §inbox/Block 2c:
 *
 *   1. Validate that `Follow.object` resolves to OUR Actor URL.
 *      (Anything else is "follow another local actor" — we don't have
 *      those in v0.1; silently 202.)
 *   2. Upsert the follower row in `pending_accept` state.
 *   3. Enqueue the matching `Accept` activity on the outbound push
 *      queue, addressed to the remote actor's inbox. The push worker
 *      (Block 2d) signs + delivers it on the next scheduler tick.
 *   4. Return 202 immediately — keeps the inbox latency predictable.
 */
final class FollowHandler
{
    public function __construct(
        private readonly FollowerStore $followers,
        private readonly OutboundQueue $queue,
        private readonly string $localActorUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $activity Verified `Follow` body.
     */
    public function handle(array $activity, FetchedKey $verifiedSender): ResponseInterface
    {
        $target = $this->extractTarget($activity);
        if ($target === null
            || Canonicalizer::ownerUrl($target) !== Canonicalizer::ownerUrl($this->localActorUrl)) {
            // Follow aimed elsewhere; ignore.
            return new Response(202, [], '');
        }

        $this->followers->upsertPending(
            actorUrl:       $verifiedSender->ownerUrl,
            inboxUrl:       $verifiedSender->inboxUrl,
            sharedInboxUrl: $verifiedSender->sharedInboxUrl,
        );

        $accept = $this->buildAccept($activity);
        $this->queue->enqueue(
            activity:       $accept,
            recipientInbox: $verifiedSender->inboxUrl,
            actor:          $this->localActorUrl,
        );

        return new Response(202, [], '');
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function extractTarget(array $activity): ?string
    {
        $object = $activity['object'] ?? null;
        if (\is_string($object) && $object !== '') {
            return $object;
        }
        if (\is_array($object) && isset($object['id']) && \is_string($object['id']) && $object['id'] !== '') {
            return $object['id'];
        }
        return null;
    }

    /**
     * @param array<string, mixed> $follow
     * @return array<string, mixed>
     */
    private function buildAccept(array $follow): array
    {
        $followId   = (string) ($follow['id'] ?? '');
        $followActor = (string) ($follow['actor'] ?? '');
        $acceptId = $this->localActorUrl . '#accept-' . hash('sha256', $followId);

        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $acceptId,
            'type'     => 'Accept',
            'actor'    => $this->localActorUrl,
            'to'       => [$followActor],
            'object'   => $follow,
        ];
    }
}
