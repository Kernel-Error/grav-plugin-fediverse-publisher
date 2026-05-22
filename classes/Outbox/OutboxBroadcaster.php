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
     * @return int newly-inserted rows (excludes duplicates dropped by
     *             the UNIQUE constraint on activity_id+recipient_inbox)
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

        $inserted  = 0;
        $deduped   = 0;
        foreach ($followers as $row) {
            $wasNew = $this->queue->enqueue(
                activity:       $create,
                recipientInbox: $row['inbox_url'],
                actor:          $this->localActorUrl,
            );
            if ($wasNew) {
                $inserted++;
            } else {
                $deduped++;
            }
        }

        // Distinguish three outcomes so the operator can read the log
        // and immediately tell whether anything actually went out.
        // The v0.0.8 deploy had no way to distinguish a re-save (every
        // row deduped by UNIQUE constraint, "nothing happened") from
        // a broken hook ("handler didn't fire"); both looked identical
        // in grav.log.
        if ($inserted === 0) {
            $this->log->info('broadcast deduped — activity already queued for all followers', [
                'route'        => $page->route,
                'type'         => $asArticle ? 'Article' : 'Note',
                'activity_id'  => $create['id'] ?? null,
                'follower_count' => $deduped,
            ]);
        } elseif ($deduped > 0) {
            $this->log->info('broadcast enqueued — partial (some followers already had it)', [
                'route'    => $page->route,
                'type'     => $asArticle ? 'Article' : 'Note',
                'new'      => $inserted,
                'deduped'  => $deduped,
            ]);
        } else {
            $this->log->info('broadcast enqueued', [
                'route'    => $page->route,
                'type'     => $asArticle ? 'Article' : 'Note',
                'fan_out'  => $inserted,
            ]);
        }
        return $inserted;
    }
}
