<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Signature;

use DateTimeImmutable;

/**
 * Time abstraction so tests can freeze the clock for date-skew + cache-TTL
 * scenarios. Production uses SystemClock; unit tests inject FrozenClock.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
