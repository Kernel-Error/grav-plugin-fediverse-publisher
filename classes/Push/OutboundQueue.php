<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Push;

use PDO;

/**
 * Minimal push-queue table per ADR-001 + ADR-003 R2-1. Only the
 * schema and an `enqueue()` method land in Block 2c — that's enough
 * for FollowHandler to hand off the `Accept` activity. The worker /
 * dispatcher / signer that actually drains this queue is Block 2d.
 */
final class OutboundQueue
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS push_queue (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                activity_id      TEXT NOT NULL,
                recipient_inbox  TEXT NOT NULL,
                actor            TEXT NOT NULL,
                payload          TEXT NOT NULL,
                status           TEXT NOT NULL DEFAULT "pending",
                attempt_count    INTEGER NOT NULL DEFAULT 0,
                next_attempt_at  INTEGER NOT NULL,
                last_http_status INTEGER,
                last_error       TEXT,
                worker_id        TEXT,
                claimed_at       INTEGER,
                created_at       INTEGER NOT NULL,
                updated_at       INTEGER NOT NULL,
                UNIQUE (activity_id, recipient_inbox)
            )'
        );
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_push_queue_status_next
               ON push_queue (status, next_attempt_at)'
        );
    }

    /**
     * Enqueue an activity for one recipient. Idempotent on
     * (activity_id, recipient_inbox) per ADR-003 R2-1: re-enqueueing
     * the same pair is a no-op.
     *
     * @param array<string, mixed> $activity AS 2.0, unsigned
     */
    public function enqueue(array $activity, string $recipientInbox, string $actor): void
    {
        $now = time();
        $payload = (string) json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO push_queue
                (activity_id, recipient_inbox, actor, payload, status, attempt_count, next_attempt_at, created_at, updated_at)
             VALUES
                (:aid, :inbox, :actor, :payload, "pending", 0, :ts, :ts, :ts)'
        );
        $stmt->execute([
            ':aid'     => (string) ($activity['id'] ?? ''),
            ':inbox'   => $recipientInbox,
            ':actor'   => $actor,
            ':payload' => $payload,
            ':ts'      => $now,
        ]);
    }

    /**
     * Reclaim rows stuck in `processing` longer than the heartbeat
     * threshold — they belong to a worker that crashed mid-delivery
     * (ADR-003 R2-1).
     *
     * @return int rows reclaimed
     */
    public function reclaimStuck(int $reclaimThresholdSeconds): int
    {
        $cutoff = time() - $reclaimThresholdSeconds;
        $stmt = $this->pdo->prepare(
            'UPDATE push_queue
                SET status = "pending", worker_id = NULL, claimed_at = NULL, updated_at = :now
              WHERE status = "processing" AND claimed_at < :cutoff'
        );
        $stmt->execute([':now' => time(), ':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    /**
     * Atomically claim up to N rows for a worker. Two-step:
     *   1. SELECT eligible row IDs under BEGIN IMMEDIATE
     *   2. UPDATE those rows to `processing` with our worker_id
     *
     * @return list<int> Claimed row IDs
     */
    public function claimBatch(string $workerId, int $limit): array
    {
        $now = time();
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM push_queue
                  WHERE status = "pending" AND next_attempt_at <= :now
                  ORDER BY next_attempt_at ASC, id ASC
                  LIMIT :lim'
            );
            $stmt->bindValue(':now', $now, \PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            /** @var list<int> $ids */
            $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
            if ($ids === []) {
                $this->pdo->commit();
                return [];
            }
            $placeholders = implode(',', array_fill(0, \count($ids), '?'));
            $upd = $this->pdo->prepare(
                "UPDATE push_queue
                    SET status = 'processing', worker_id = ?, claimed_at = ?, updated_at = ?
                  WHERE id IN ($placeholders)"
            );
            $upd->execute(array_merge([$workerId, $now, $now], $ids));
            $this->pdo->commit();
            return $ids;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function loadById(int $id): ?QueueRecord
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, activity_id, recipient_inbox, actor, payload, attempt_count
               FROM push_queue WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $decoded = json_decode((string) $row['payload'], true);
        return new QueueRecord(
            id:             (int) $row['id'],
            activityId:     (string) $row['activity_id'],
            recipientInbox: (string) $row['recipient_inbox'],
            actor:          (string) $row['actor'],
            payload:        \is_array($decoded) ? $decoded : [],
            attemptCount:   (int) $row['attempt_count'],
        );
    }

    public function heartbeat(int $id, string $workerId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE push_queue
                SET claimed_at = :ts, updated_at = :ts
              WHERE id = :id AND worker_id = :w AND status = "processing"'
        );
        $stmt->execute([':id' => $id, ':w' => $workerId, ':ts' => time()]);
        return $stmt->rowCount() === 1;
    }

    public function markDone(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE push_queue
                SET status = "done", last_http_status = 200, updated_at = :ts
              WHERE id = :id'
        );
        $stmt->execute([':id' => $id, ':ts' => time()]);
    }

    public function markDead(int $id, int $status, string $reason): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE push_queue
                SET status = "dead",
                    last_http_status = :s,
                    last_error = :r,
                    updated_at = :ts
              WHERE id = :id'
        );
        $stmt->execute([':id' => $id, ':s' => $status, ':r' => $reason, ':ts' => time()]);
    }

    public function reschedule(int $id, int $newAttemptCount, int $delaySeconds, int $lastStatus, string $reason): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE push_queue
                SET status = "pending",
                    attempt_count = :c,
                    next_attempt_at = :next,
                    last_http_status = :s,
                    last_error = :r,
                    worker_id = NULL,
                    claimed_at = NULL,
                    updated_at = :ts
              WHERE id = :id'
        );
        $stmt->execute([
            ':id'   => $id,
            ':c'    => $newAttemptCount,
            ':next' => time() + $delaySeconds,
            ':s'    => $lastStatus,
            ':r'    => $reason,
            ':ts'   => time(),
        ]);
    }
}
