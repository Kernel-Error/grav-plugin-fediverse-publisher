<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Signature;

/**
 * Verifier outcome.
 *
 * `status`:
 *   - 200 — authenticated + dedup'd fresh, dispatch the activity
 *   - 202 — authenticated but a duplicate of an earlier inbox post
 *           (spec: idempotent inbox, drop silently)
 *   - 400 — algorithm parameter not in the accepted set
 *   - 401 — anything that means "we don't trust this request"
 *           (signature fail, identity mismatch, key unfetchable, ...)
 *   - 415 — wrong Content-Type — surfaced from the controller, not
 *           generated here
 */
final class VerificationResult
{
    /**
     * @param array<string, mixed>|null $activity Parsed JSON body, only
     *                                            set on `ok()` results.
     */
    private function __construct(
        public readonly int $status,
        public readonly string $reason,
        public readonly ?array $activity,
        public readonly ?FetchedKey $verifiedKey,
    ) {
    }

    /** @param array<string, mixed> $activity */
    public static function ok(array $activity, FetchedKey $key): self
    {
        return new self(200, 'verified', $activity, $key);
    }

    public static function duplicate(): self
    {
        return new self(202, 'duplicate', null, null);
    }

    public static function rejected(int $status, string $reason): self
    {
        return new self($status, $reason, null, null);
    }
}
