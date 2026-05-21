<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Storage;

use PDO;

/**
 * Followers table per ADR-001. Single row per remote actor URL,
 * with a status column distinguishing pending-Accept-push from
 * already-accepted, and counters that ADR-003 R2-2 needs for the
 * follower-stale logic.
 */
final class FollowerStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS followers (
                actor_url           TEXT PRIMARY KEY,
                inbox_url           TEXT NOT NULL,
                shared_inbox_url    TEXT,
                status              TEXT NOT NULL,         -- pending_accept | accepted | stale
                consecutive_404     INTEGER NOT NULL DEFAULT 0,
                first_404_at        INTEGER,
                created_at          INTEGER NOT NULL,
                updated_at          INTEGER NOT NULL
            )'
        );
    }

    public function upsertPending(string $actorUrl, string $inboxUrl, ?string $sharedInboxUrl): void
    {
        $now = time();
        $stmt = $this->pdo->prepare(
            'INSERT INTO followers
                (actor_url, inbox_url, shared_inbox_url, status, created_at, updated_at)
             VALUES
                (:a, :i, :si, :st, :ts, :ts)
             ON CONFLICT(actor_url) DO UPDATE SET
                inbox_url        = excluded.inbox_url,
                shared_inbox_url = excluded.shared_inbox_url,
                status           = CASE WHEN status = :stale THEN :st ELSE status END,
                updated_at       = excluded.updated_at'
        );
        $stmt->execute([
            ':a'     => $actorUrl,
            ':i'     => $inboxUrl,
            ':si'    => $sharedInboxUrl,
            ':st'    => 'pending_accept',
            ':stale' => 'stale',
            ':ts'    => $now,
        ]);
    }

    public function markAccepted(string $actorUrl): void
    {
        // String literals in SQL must be SINGLE-quoted — see the note
        // at the top of OutboundQueue.php. SQLite under PHP 8.3 reads
        // double-quoted tokens as identifiers, which crashes with
        // "no such column: accepted".
        $stmt = $this->pdo->prepare(
            "UPDATE followers
                SET status     = 'accepted',
                    updated_at = :ts
              WHERE actor_url = :a"
        );
        $stmt->execute([':a' => $actorUrl, ':ts' => time()]);
    }

    public function remove(string $actorUrl): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM followers WHERE actor_url = :a');
        $stmt->execute([':a' => $actorUrl]);
        return $stmt->rowCount() > 0;
    }

    public function exists(string $actorUrl): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM followers WHERE actor_url = :a');
        $stmt->execute([':a' => $actorUrl]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return list<array{actor_url:string,inbox_url:string,shared_inbox_url:?string,status:string}>
     */
    public function listActive(): array
    {
        $stmt = $this->pdo->query(
            "SELECT actor_url, inbox_url, shared_inbox_url, status
               FROM followers
              WHERE status != 'stale'
              ORDER BY actor_url"
        );
        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    /**
     * Page of accepted-and-pending follower actor URLs, oldest-first.
     * Used by the followers endpoint to publish an OrderedCollection.
     *
     * @return list<string>
     */
    public function listForCollection(int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT actor_url FROM followers
              WHERE status != 'stale'
              ORDER BY created_at ASC, actor_url ASC
              LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function countForCollection(): int
    {
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM followers WHERE status != 'stale'"
        );
        return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
    }
}
