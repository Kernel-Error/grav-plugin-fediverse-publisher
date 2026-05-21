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

    public function testHostBaseCheckSkippedWhenNotProvided(): void
    {
        // Backwards-compat: existing callers that pre-date the
        // hostBase check still construct PreflightCheck with two
        // args. Treat missing hostBase as "not checked", not failure.
        $check = new PreflightCheck(hasPdoSqlite: true, baseUrlPath: '');

        self::assertTrue($check->isHealthy());
    }

    public function testPublishableHostBasePasses(): void
    {
        $check = new PreflightCheck(
            hasPdoSqlite:     true,
            baseUrlPath:      '',
            resolvedHostBase: 'https://blog.example.com',
        );

        self::assertTrue($check->isHealthy());
    }

    public function testLocalhostHostBaseRejected(): void
    {
        // The v0.0.4 production bug: CLI scheduler falls back to
        // http://localhost as the canonical host. Preflight must
        // refuse to run rather than emit a keyId that Mastodon
        // would reject as a private-network reference.
        $check = new PreflightCheck(
            hasPdoSqlite:     true,
            baseUrlPath:      '',
            resolvedHostBase: 'http://localhost',
        );

        self::assertFalse($check->isHealthy());
        self::assertStringContainsString('canonical_host', $check->getErrors()[0]);
        self::assertStringContainsString('localhost', $check->getErrors()[0]);
    }

    public function testHttpOnlyHostBaseRejected(): void
    {
        $check = new PreflightCheck(
            hasPdoSqlite:     true,
            baseUrlPath:      '',
            resolvedHostBase: 'http://blog.example.com',
        );

        self::assertFalse($check->isHealthy());
        self::assertStringContainsString('canonical_host', $check->getErrors()[0]);
    }

    public function testPrivateIpLiteralRejected(): void
    {
        $check = new PreflightCheck(
            hasPdoSqlite:     true,
            baseUrlPath:      '',
            resolvedHostBase: 'https://10.0.0.5',
        );

        self::assertFalse($check->isHealthy());
    }
}
