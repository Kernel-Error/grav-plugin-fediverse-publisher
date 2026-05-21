<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Signature;

/**
 * Date freshness check — Mastodon-aligned window per ADR-002 A-4 /
 * R2-4. A `Date` header is acceptable iff
 *   now - 12 h ≤ Date ≤ now + 1 h.
 *
 * That tolerates a generous server-side clock skew without letting
 * arbitrarily old signed requests survive forever.
 */
final class DateChecker
{
    public const PAST_WINDOW_SECONDS   = 12 * 3600;
    public const FUTURE_WINDOW_SECONDS = 1 * 3600;

    public function __construct(private readonly Clock $clock)
    {
    }

    public function isFresh(string $dateHeader): bool
    {
        if ($dateHeader === '') {
            return false;
        }
        $ts = \strtotime($dateHeader);
        if ($ts === false) {
            return false;
        }
        $now = $this->clock->now()->getTimestamp();
        return ($now - self::PAST_WINDOW_SECONDS) <= $ts
            && $ts <= ($now + self::FUTURE_WINDOW_SECONDS);
    }
}
