<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Push;

/**
 * Exponential backoff with full jitter per ADR-003 §"Retry policy".
 *
 * Schedule (attempt → next-delay): 1m, 5m, 30m, 2h, 12h, 24h. After
 * the 7th attempt the queue row is marked `dead`. Each delay is
 * multiplied by a random factor in `[0.5, 1.5]` to avoid thundering-
 * herd against a peer that just came back up.
 */
final class RetryPolicy
{
    public const MAX_ATTEMPTS = 7;

    /** @var list<int> Nominal delays in seconds, indexed by attempt count. */
    private const SCHEDULE = [
        60,        // 1 minute  → attempt 2
        300,       // 5 minutes → attempt 3
        1800,      // 30 minutes
        7200,      // 2 hours
        43200,     // 12 hours
        86400,     // 24 hours
    ];

    /**
     * @param int $attemptCount the number of attempts that have ALREADY happened
     * @return int|null seconds until the next retry, or null if exhausted
     */
    public function nextDelaySeconds(int $attemptCount): ?int
    {
        if ($attemptCount >= self::MAX_ATTEMPTS) {
            return null;
        }
        $nominal = self::SCHEDULE[\min($attemptCount - 1, \count(self::SCHEDULE) - 1)] ?? self::SCHEDULE[0];
        // Full jitter: uniform in [0.5×, 1.5×]
        $jittered = $nominal * (0.5 + \mt_rand(0, 1000) / 1000.0);
        return (int) \round($jittered);
    }
}
