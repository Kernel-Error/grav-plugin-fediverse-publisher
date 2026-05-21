<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * Tiny in-memory PSR-3 logger used by tests that need to assert
 * something was logged. Captures level + message + context for each
 * call, with `lastDebug` / `lastInfo` / `lastWarning` shortcuts for
 * the common single-call assertion path.
 *
 * Signature matches the psr/log v1.1.x untyped contract (which the
 * plugin pins to avoid colliding with Grav 1.7's bundled v1).
 */
final class TestLogger extends AbstractLogger
{
    /** @var list<array{level:string,message:string,context:array<string,mixed>}> */
    public array $records = [];

    /** @var array<string,mixed>|null */
    public ?array $lastDebug = null;
    /** @var array<string,mixed>|null */
    public ?array $lastInfo = null;
    /** @var array<string,mixed>|null */
    public ?array $lastWarning = null;

    /**
     * @param mixed                $level
     * @param mixed                $message Stringable | string under the
     *                                       hood, untyped per psr/log v1.
     * @param array<string,mixed>  $context
     */
    public function log($level, $message, array $context = []): void
    {
        $lvl = (string) $level;
        $this->records[] = [
            'level'   => $lvl,
            'message' => (string) $message,
            'context' => $context,
        ];
        match ($lvl) {
            'debug'   => $this->lastDebug = $context,
            'info'    => $this->lastInfo = $context,
            'warning' => $this->lastWarning = $context,
            default   => null,
        };
    }
}
