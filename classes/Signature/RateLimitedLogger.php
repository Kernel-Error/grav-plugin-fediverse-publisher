<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Signature;

use Psr\Log\LoggerInterface;

/**
 * Writes one warning per canonical keyId per minute. Buckets are
 * keyed by Canonicalizer::ownerUrl() to prevent an attacker from
 * sidestepping the limit with cosmetic variation (R2-2).
 *
 * Tiny in-memory bucket map — sufficient inside a single request, no
 * cross-request state required (each PHP-FPM worker forgets buckets
 * after the request, which is fine: a flood comes in on one request,
 * we log once, the rest of the flood within that request gets
 * suppressed).
 *
 * For cross-request rate-limiting against a sustained attacker, the
 * actor_key_cache.last_failure_at column already imposes a 15-minute
 * negative window — that's the real defence; this class is just
 * log-spam control.
 */
final class RateLimitedLogger
{
    public const BUCKET_TTL_SECONDS = 60;

    /** @var array<string, int> bucket → last-emit timestamp */
    private array $buckets = [];

    public function __construct(
        private readonly LoggerInterface $log,
        private readonly Clock $clock,
    ) {
    }

    public function warn(string $keyIdOrAny, string $reason): void
    {
        $bucket = Canonicalizer::ownerUrl($keyIdOrAny);
        if ($bucket === '') {
            $bucket = '<unparseable>';
        }
        $now = $this->clock->now()->getTimestamp();
        $last = $this->buckets[$bucket] ?? 0;
        if (($now - $last) < self::BUCKET_TTL_SECONDS) {
            return;
        }
        $this->buckets[$bucket] = $now;
        // Body and signature deliberately NOT included to limit
        // information disclosure on noisy logs.
        $this->log->warning('inbox verification rejected', [
            'keyId'  => $bucket,
            'reason' => $reason,
        ]);
    }
}
