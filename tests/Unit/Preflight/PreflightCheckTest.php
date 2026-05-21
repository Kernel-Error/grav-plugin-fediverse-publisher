<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Preflight;

use Grav\Plugin\FediversePublisher\Preflight\PreflightCheck;
use PHPUnit\Framework\TestCase;

final class PreflightCheckTest extends TestCase
{
    public function testHealthyAtDocumentRootWithPdoSqlite(): void
    {
        $check = new PreflightCheck(hasPdoSqlite: true, baseUrlPath: '');

        self::assertTrue($check->isHealthy());
        self::assertSame([], $check->getErrors());
    }

    public function testHealthyAtDocumentRootSlashOnly(): void
    {
        $check = new PreflightCheck(hasPdoSqlite: true, baseUrlPath: '/');

        self::assertTrue($check->isHealthy());
    }

    public function testRejectsSubdirectoryInstall(): void
    {
        $check = new PreflightCheck(hasPdoSqlite: true, baseUrlPath: '/grav');

        self::assertFalse($check->isHealthy());
        self::assertCount(1, $check->getErrors());
        self::assertStringContainsString('document root', $check->getErrors()[0]);
        self::assertStringContainsString('/grav', $check->getErrors()[0]);
    }

    public function testRejectsNestedSubdirectoryInstall(): void
    {
        $check = new PreflightCheck(hasPdoSqlite: true, baseUrlPath: '/sites/blog');

        self::assertFalse($check->isHealthy());
        self::assertStringContainsString('/sites/blog', $check->getErrors()[0]);
    }

    public function testMissingPdoSqliteIsFatal(): void
    {
        $check = new PreflightCheck(hasPdoSqlite: false, baseUrlPath: '');

        self::assertFalse($check->isHealthy());
        self::assertCount(1, $check->getErrors());
        self::assertStringContainsString('pdo_sqlite', $check->getErrors()[0]);
    }

    public function testBothFailuresReportedTogether(): void
    {
        $check = new PreflightCheck(hasPdoSqlite: false, baseUrlPath: '/grav');

        self::assertFalse($check->isHealthy());
        self::assertCount(2, $check->getErrors());
    }
}
