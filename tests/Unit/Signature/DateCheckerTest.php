<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Signature;

use DateTimeImmutable;
use Grav\Plugin\FediversePublisher\Signature\DateChecker;
use Grav\Plugin\FediversePublisher\Signature\FrozenClock;
use PHPUnit\Framework\TestCase;

final class DateCheckerTest extends TestCase
{
    private const NOW = '2026-05-21T12:00:00+00:00';

    public function testInsideWindow(): void
    {
        $c = $this->check();
        self::assertTrue($c->isFresh($this->fmt('2026-05-21T11:59:00+00:00')));
        self::assertTrue($c->isFresh($this->fmt('2026-05-21T12:30:00+00:00')));
        self::assertTrue($c->isFresh($this->fmt('2026-05-21T00:00:01+00:00')));   // ~12h ago + 1s
    }

    public function testTooOld(): void
    {
        self::assertFalse($this->check()->isFresh($this->fmt('2026-05-20T23:59:00+00:00')));
    }

    public function testTooFarInFuture(): void
    {
        self::assertFalse($this->check()->isFresh($this->fmt('2026-05-21T13:00:30+00:00')));
    }

    public function testMissingDate(): void
    {
        self::assertFalse($this->check()->isFresh(''));
    }

    public function testMalformed(): void
    {
        self::assertFalse($this->check()->isFresh('not a date'));
    }

    public function testRfc1123(): void
    {
        self::assertTrue($this->check()->isFresh('Thu, 21 May 2026 11:00:00 GMT'));
    }

    private function check(): DateChecker
    {
        return new DateChecker(new FrozenClock(new DateTimeImmutable(self::NOW)));
    }

    private function fmt(string $iso): string
    {
        return (new DateTimeImmutable($iso))->format(\DateTimeInterface::RFC7231);
    }
}
