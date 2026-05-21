<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Signature;

/**
 * Orchestrates the cache + fetcher with negative-caching. This is the
 * ONLY component that may trigger a remote HTTP call from the
 * verifier path (R2-1).
 */
final class KeyProvider
{
    public function __construct(
        private readonly KeyCache $cache,
        private readonly KeyFetcher $fetcher,
        private readonly RateLimitedLogger $log,
        private readonly Clock $clock,
    ) {
    }

    public function getForVerification(string $keyId): ?FetchedKey
    {
        $canonicalKeyId = Canonicalizer::keyId($keyId);
        if ($canonicalKeyId === '') {
            $this->log->warn($keyId, 'keyId failed canonicalisation');
            return null;
        }
        // The cache is keyed by the canonical keyId. For fragment-style
        // keyIds that coincidentally equals the owner URL, but for
        // path-style keyIds (GTS, Pleroma) it does not — the owner is
        // a separate field stored alongside.
        $cacheKey = $canonicalKeyId;
        $now      = $this->clock->now()->getTimestamp();

        $cached = $this->cache->lookup($cacheKey);
        if ($cached !== null) {
            if ($cached->isInNegativeWindow($now)) {
                return null;   // fast-fail without I/O
            }
            if ($cached->isFreshSuccess($now)) {
                return new FetchedKey(
                    keyId:          $cached->keyId,
                    ownerUrl:       $cached->ownerUrl,
                    pem:            (string) $cached->pem,
                    inboxUrl:       (string) $cached->inboxUrl,
                    sharedInboxUrl: $cached->sharedInboxUrl,
                    fetchedAt:      $this->clock->now()->setTimestamp((int) $cached->fetchedAt),
                );
            }
        }

        try {
            $fetched = $this->fetcher->fetch($keyId);
            $this->cache->putSuccess($fetched);
            return $fetched;
        } catch (KeyFetchException $e) {
            $this->log->warn($keyId, 'keyfetch failed: ' . $e->getMessage());
            $this->cache->putFailure($cacheKey, $now);
            return null;
        }
    }
}
