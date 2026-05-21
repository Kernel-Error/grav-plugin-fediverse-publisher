<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Signature;

/**
 * Internal cache-row value object externalised from KeyCache so
 * KeyProvider can ask "fresh-success?" / "negative-window?" without
 * re-reading rows.
 */
final class CacheEntry
{
    public function __construct(
        public readonly string $ownerUrl,
        public readonly string $keyId,
        public readonly ?string $pem,
        public readonly ?string $inboxUrl,
        public readonly ?string $sharedInboxUrl,
        public readonly ?int $fetchedAt,
        public readonly ?int $lastFailureAt,
    ) {
    }

    public function isFreshSuccess(int $now): bool
    {
        if ($this->pem === null || $this->fetchedAt === null) {
            return false;
        }
        return ($now - $this->fetchedAt) <= KeyCache::POSITIVE_TTL_SECONDS;
    }

    public function isInNegativeWindow(int $now): bool
    {
        if ($this->lastFailureAt === null) {
            return false;
        }
        return ($now - $this->lastFailureAt) <= KeyCache::NEGATIVE_TTL_SECONDS;
    }
}
