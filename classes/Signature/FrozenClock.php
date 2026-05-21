<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Signature;

use DateTimeImmutable;

final class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modify): void
    {
        $this->now = $this->now->modify($modify);
    }
}
