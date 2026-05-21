<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Push;

use Grav\Plugin\FediversePublisher\Push\DeliveryOutcome;
use Grav\Plugin\FediversePublisher\Push\FailureClassifier;
use PHPUnit\Framework\TestCase;

final class FailureClassifierTest extends TestCase
{
    /**
     * @dataProvider statusMatrix
     */
    public function testFromStatus(int $status, DeliveryOutcome $expected): void
    {
        self::assertSame($expected, (new FailureClassifier())->fromStatus($status));
    }

    /**
     * @return iterable<string, array{int, DeliveryOutcome}>
     */
    public static function statusMatrix(): iterable
    {
        yield '200 ok'           => [200, DeliveryOutcome::Success];
        yield '202 accepted'     => [202, DeliveryOutcome::Success];
        yield '204 no content'   => [204, DeliveryOutcome::Success];
        yield '410 gone'         => [410, DeliveryOutcome::GoneForever];
        yield '401 unauthorized' => [401, DeliveryOutcome::Permanent];
        yield '403 forbidden'    => [403, DeliveryOutcome::Permanent];
        yield '404 not found'    => [404, DeliveryOutcome::Permanent];
        yield '422 unprocessable'=> [422, DeliveryOutcome::Permanent];
        yield '429 rate-limited' => [429, DeliveryOutcome::Transient];
        yield '500 server error' => [500, DeliveryOutcome::Transient];
        yield '502 bad gateway'  => [502, DeliveryOutcome::Transient];
        yield '503 unavailable'  => [503, DeliveryOutcome::Transient];
        yield '504 timeout'      => [504, DeliveryOutcome::Transient];
    }

    public function testNetworkErrorAlwaysTransient(): void
    {
        self::assertSame(DeliveryOutcome::Transient, (new FailureClassifier())->fromException());
    }
}
