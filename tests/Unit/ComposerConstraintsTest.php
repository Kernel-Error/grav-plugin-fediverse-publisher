<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards against the v0.1.0 regression class: a hard `psr/log: ^1.1`
 * pin. That pin was correct in v0.0.2 (a transitive psr/log v3 in the
 * plugin's own vendor collided with Grav 1.7's bundled v1 and 500'd the
 * site), but it had a delayed side effect — Grav core 1.7.53 ships a
 * newer psr/log, and GPM's self-upgrade preflight refuses to advance the
 * core while any plugin still demands `^1.1`. v0.1.1 widened the range.
 *
 * The plugin only *consumes* a PSR-3 logger, so accepting v2/v3 is safe.
 * This test keeps the constraint from silently snapping back to v1-only
 * and pinning a production site to an old Grav patch line again.
 */
final class ComposerConstraintsTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function composerRequire(): array
    {
        $path = \dirname(__DIR__, 2) . '/composer.json';
        $raw  = (string) file_get_contents($path);
        self::assertNotSame('', $raw, 'composer.json unreadable');

        /** @var array{require?:array<string,string>} $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data['require'] ?? null, 'composer.json has no require block');

        return $data['require'];
    }

    public function testPsrLogConstraintIsDeclared(): void
    {
        $require = $this->composerRequire();
        self::assertArrayHasKey('psr/log', $require, 'psr/log requirement missing');
    }

    /**
     * The constraint must admit psr/log v2 and v3, otherwise GPM blocks
     * the Grav core upgrade again. We assert by resolving the declared
     * constraint against representative versions rather than matching a
     * literal string, so a different-but-equivalent spelling still passes.
     */
    public function testPsrLogConstraintAcceptsV2AndV3(): void
    {
        $constraint = $this->composerRequire()['psr/log'];

        self::assertTrue(
            $this->satisfies('1.1.4', $constraint),
            "psr/log constraint '{$constraint}' must still accept v1 (Grav 1.7.52)."
        );
        self::assertTrue(
            $this->satisfies('2.0.0', $constraint),
            "psr/log constraint '{$constraint}' must accept v2."
        );
        self::assertTrue(
            $this->satisfies('3.0.0', $constraint),
            "psr/log constraint '{$constraint}' must accept v3 (Grav 1.7.53+ / Grav 2.0)."
        );
    }

    /**
     * Minimal caret/or-constraint evaluator — enough for the simple
     * `^1.1 || ^2.0 || ^3.0` style the project uses. Avoids pulling
     * composer/semver into the test dependencies just for this guard.
     */
    private function satisfies(string $version, string $constraint): bool
    {
        foreach (explode('||', $constraint) as $clause) {
            $clause = trim($clause);
            if ($clause === '' || $clause[0] !== '^') {
                continue;
            }
            $base  = substr($clause, 1);
            $major = (int) explode('.', $base)[0];
            $vMaj  = (int) explode('.', $version)[0];
            if ($vMaj === $major && version_compare($version, $base, '>=')) {
                return true;
            }
        }

        return false;
    }
}
