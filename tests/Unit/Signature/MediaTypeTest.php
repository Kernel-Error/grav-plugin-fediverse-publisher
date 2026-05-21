<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Signature;

use Grav\Plugin\FediversePublisher\Signature\MediaType;
use PHPUnit\Framework\TestCase;

final class MediaTypeTest extends TestCase
{
    public function testActivityJsonAccepted(): void
    {
        self::assertTrue(MediaType::isActivityPubJson('application/activity+json'));
        self::assertTrue(MediaType::isActivityPubJson('application/activity+json; charset=utf-8'));
    }

    public function testLdJsonRequiresAsProfile(): void
    {
        self::assertFalse(MediaType::isActivityPubJson('application/ld+json'));
        self::assertTrue(MediaType::isActivityPubJson(
            'application/ld+json; profile="https://www.w3.org/ns/activitystreams"'
        ));
        self::assertTrue(MediaType::isActivityPubJson(
            'application/ld+json;profile=https://www.w3.org/ns/activitystreams'
        ));
    }

    public function testPlainJsonRejected(): void
    {
        self::assertFalse(MediaType::isActivityPubJson('application/json'));
        self::assertFalse(MediaType::isActivityPubJson('application/json; charset=utf-8'));
    }

    public function testParameterInjectionRejected(): void
    {
        // The whole point of the R3-6 fix: this MUST NOT match.
        self::assertFalse(MediaType::isActivityPubJson(
            'text/plain; x=application/activity+json'
        ));
    }

    public function testCaseInsensitive(): void
    {
        self::assertTrue(MediaType::isActivityPubJson('Application/Activity+JSON'));
    }

    public function testEmptyHeader(): void
    {
        self::assertFalse(MediaType::isActivityPubJson(''));
    }
}
