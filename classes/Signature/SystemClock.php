<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Signature;

use DateTimeImmutable;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
