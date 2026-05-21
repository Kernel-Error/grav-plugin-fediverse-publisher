<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Outbox;

use Grav\Plugin\FediversePublisher\Push\OutboundQueue;
use Grav\Plugin\FediversePublisher\Storage\FollowerStore;
use Psr\Log\LoggerInterface;

/**
 * Page-save → fan-out → push-queue.
 *
 * When a Grav page that falls under the configured blog path is saved,
 * this builds a `Create` activity wrapping a `Note`/`Article` (per
 * ADR-004 §2 thresholds) and enqueues one row per follower on the
 * outbound push queue. The dispatcher picks them up on the next tick.
 *
 * Idempotency: enqueueing the same (activity_id, recipient_inbox) is
 * a no-op (ADR-003 R2-1). So calling broadcast twice for the same
 * page is safe — only newly-added followers get a row.
 *
 * Framework-agnostic: the Grav-side glue passes us the PageRecord.
 * Tests use a stub FollowerStore.
 */
final class OutboxBroadcaster
{
    public function __construct(
        private readonly FollowerStore $followers,
        private readonly OutboundQueue $queue,
        private readonly ActivityTransformer $transformer,
        private readonly string $localActorUrl,
        private readonly int $noteThreshold,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @return int rows actually enqueued (excludes duplicates)
     */
    public function broadcast(PageRecord $page): int
    {
        $followers = $this->followers->listActive();
        if ($followers === []) {
            $this->log->info('broadcast skipped — no active followers', [
                'route' => $page->route,
            ]);
            return 0;
        }

        $asArticle = $page->charCount() > $this->noteThreshold;
        $create    = $this->transformer->transformCreate($page, $asArticle);

        $enqueued = 0;
        foreach ($followers as $row) {
            // The queue is idempotent on (activity_id, recipient_inbox)
            // so we just call it for each follower.
            $this->queue->enqueue(
                activity:       $create,
                recipientInbox: $row['inbox_url'],
                actor:          $this->localActorUrl,
            );
            $enqueued++;
        }

        $this->log->info('broadcast enqueued', [
            'route'    => $page->route,
            'type'     => $asArticle ? 'Article' : 'Note',
            'fan_out'  => $enqueued,
        ]);
        return $enqueued;
    }
}
