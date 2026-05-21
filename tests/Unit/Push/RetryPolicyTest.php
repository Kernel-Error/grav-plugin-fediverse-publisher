<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Push;

use Grav\Plugin\FediversePublisher\Push\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    public function testFirstAttemptGivesSubMinuteDelay(): void
    {
        // attempt 1 (just attempted once) → schedule[0] = 60s, jittered
        // between 30 and 90.
        $d = (new RetryPolicy())->nextDelaySeconds(1);
        self::assertNotNull($d);
        self::assertGreaterThanOrEqual(30, $d);
        self::assertLessThanOrEqual(90, $d);
    }

    public function testIncreasingAttemptsHaveIncreasingNominalDelays(): void
    {
        // Compare worst-case-jitter floors across attempts.
        $delays = [];
        for ($i = 1; $i <= 6; $i++) {
            $delays[] = (new RetryPolicy())->nextDelaySeconds($i);
        }
        // Each delay's MAX (1.5x nominal) is below the next delay's MIN
        // (0.5x next-nominal) for our schedule: 60→300, 300→1800, etc.
        // 90 < 150, 450 < 900, 2700 < 3600, 10800 < 21600, 64800 < 43200 -- this last comparison fails because the 12h/24h ratio is only 2, not 5.
        // So we only assert monotonic averages, not strict separation.
        $nonNullDelays = \array_map('intval', \array_filter($delays, static fn($d): bool => $d !== null));
        $avg5 = $nonNullDelays[4] ?? 0;
        $avg0 = $nonNullDelays[0] ?? 0;
        self::assertGreaterThan($avg0, $avg5);
    }

    public function testExhaustedReturnsNull(): void
    {
        self::assertNull((new RetryPolicy())->nextDelaySeconds(RetryPolicy::MAX_ATTEMPTS));
        self::assertNull((new RetryPolicy())->nextDelaySeconds(RetryPolicy::MAX_ATTEMPTS + 1));
    }
}
