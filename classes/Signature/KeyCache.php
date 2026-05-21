<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Signature;

use PDO;

/**
 * SQLite-backed remote actor-key cache. Per ADR-001 schema + the
 * round-1 amendment that added a negative-cache column.
 *
 * Schema (created lazily by `migrate()`):
 *
 *   CREATE TABLE actor_key_cache (
 *     owner_url        TEXT PRIMARY KEY,
 *     key_id           TEXT NOT NULL,
 *     public_key_pem   TEXT,                   -- null on negative entries
 *     inbox_url        TEXT,
 *     shared_inbox_url TEXT,
 *     fetched_at       INTEGER,                -- last successful fetch
 *     last_failure_at  INTEGER                 -- last failed fetch (R2-1)
 *   );
 *
 * The Verifier keys lookups by canonical owner URL — single row per
 * remote actor, whether the last attempt succeeded or failed.
 */
final class KeyCache
{
    public const POSITIVE_TTL_SECONDS = 86_400;   // 24 h (Mastodon-aligned)
    public const NEGATIVE_TTL_SECONDS = 900;      // 15 min (R2-1)

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS actor_key_cache (
                owner_url        TEXT PRIMARY KEY,
                key_id           TEXT NOT NULL,
                public_key_pem   TEXT,
                inbox_url        TEXT,
                shared_inbox_url TEXT,
                fetched_at       INTEGER,
                last_failure_at  INTEGER
            )'
        );
    }

    public function lookup(string $ownerUrl): ?CacheEntry
    {
        $stmt = $this->pdo->prepare('SELECT * FROM actor_key_cache WHERE owner_url = :o');
        $stmt->execute([':o' => $ownerUrl]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        // owner_url column = cache key (canonical keyId).
        // key_id column     = resolved owner URL.
        return new CacheEntry(
            ownerUrl:        (string) $row['key_id'],    // the real owner (legacy column name)
            keyId:           (string) $row['owner_url'], // the cache key (legacy column name)
            pem:             $row['public_key_pem'] !== null ? (string) $row['public_key_pem'] : null,
            inboxUrl:        $row['inbox_url'] !== null ? (string) $row['inbox_url'] : null,
            sharedInboxUrl:  $row['shared_inbox_url'] !== null ? (string) $row['shared_inbox_url'] : null,
            fetchedAt:       $row['fetched_at'] !== null ? (int) $row['fetched_at'] : null,
            lastFailureAt:   $row['last_failure_at'] !== null ? (int) $row['last_failure_at'] : null,
        );
    }

    public function putSuccess(FetchedKey $key): void
    {
        // `owner_url` column name is historical — it actually holds the
        // canonical keyId (used as the cache key). The real owner is in
        // `key_id`'s parent column. Renamed semantically in code; the
        // schema column rename can land in a v0.2 migration.
        $stmt = $this->pdo->prepare(
            'INSERT INTO actor_key_cache
                (owner_url, key_id, public_key_pem, inbox_url, shared_inbox_url, fetched_at, last_failure_at)
             VALUES
                (:cachekey, :owner, :pem, :inbox, :shared, :ts, NULL)
             ON CONFLICT(owner_url) DO UPDATE SET
                key_id           = excluded.key_id,
                public_key_pem   = excluded.public_key_pem,
                inbox_url        = excluded.inbox_url,
                shared_inbox_url = excluded.shared_inbox_url,
                fetched_at       = excluded.fetched_at,
                last_failure_at  = NULL'
        );
        $stmt->execute([
            ':cachekey' => $key->keyId,        // canonical keyId IS the cache key
            ':owner'    => $key->ownerUrl,     // resolved owner URL
            ':pem'      => $key->pem,
            ':inbox'    => $key->inboxUrl,
            ':shared'   => $key->sharedInboxUrl,
            ':ts'       => $key->fetchedAt->getTimestamp(),
        ]);
    }

    public function putFailure(string $ownerUrl, int $unixTs): void
    {
        // Preserve the prior PEM (if any) so a brief upstream blip
        // doesn't wipe a usable key — the negative-cache TTL is what
        // gates re-fetching, not the PEM presence.
        $stmt = $this->pdo->prepare(
            'INSERT INTO actor_key_cache
                (owner_url, key_id, public_key_pem, inbox_url, shared_inbox_url, fetched_at, last_failure_at)
             VALUES
                (:o, :o, NULL, NULL, NULL, NULL, :ts)
             ON CONFLICT(owner_url) DO UPDATE SET
                last_failure_at = excluded.last_failure_at'
        );
        $stmt->execute([':o' => $ownerUrl, ':ts' => $unixTs]);
    }
}
