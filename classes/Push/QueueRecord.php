<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Push;

/**
 * Value object representing a single row in `push_queue`. Internal —
 * the queue tables wrap rows in this shape so callers don't deal with
 * raw PDO arrays.
 */
final class QueueRecord
{
    /**
     * @param array<string, mixed> $payload Unsigned AS 2.0 activity
     */
    public function __construct(
        public readonly int $id,
        public readonly string $activityId,
        public readonly string $recipientInbox,
        public readonly string $actor,
        public readonly array $payload,
        public readonly int $attemptCount,
    ) {
    }
}
