<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Storage;

use PDO;

/**
 * Inbox dedup index per ADR-001. PRIMARY KEY on `activity_id` gives
 * us idempotency for free (`INSERT OR IGNORE` returns 0 rows on a
 * duplicate).
 *
 * `raw_json` is kept for ~30 days for debugging then pruned via a
 * scheduler tick (ADR-001 A-3 — pruner is Block 2d territory).
 */
final class InboxLog
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS inbox_log (
                activity_id  TEXT PRIMARY KEY,
                actor_url    TEXT NOT NULL,
                type         TEXT NOT NULL,
                received_at  INTEGER NOT NULL,
                raw_json     TEXT
            )'
        );
    }

    /**
     * @return bool true on fresh insert (caller dispatches the activity),
     *              false on duplicate (caller returns 202 silently)
     */
    public function insertIfFresh(string $activityId, string $actorUrl, string $type, string $rawJson): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO inbox_log
                (activity_id, actor_url, type, received_at, raw_json)
             VALUES (:id, :a, :t, :ts, :raw)'
        );
        $stmt->execute([
            ':id'  => $activityId,
            ':a'   => $actorUrl,
            ':t'   => $type,
            ':ts'  => \time(),
            ':raw' => $rawJson,
        ]);
        return $stmt->rowCount() === 1;
    }

    public function has(string $activityId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM inbox_log WHERE activity_id = :id');
        $stmt->execute([':id' => $activityId]);
        return $stmt->fetchColumn() !== false;
    }
}
