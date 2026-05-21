<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Push;

/**
 * Maps an HTTP attempt result (or a network exception) to a
 * DeliveryOutcome per ADR-003 §"HTTP failure semantics".
 *
 * Truth table:
 *
 *   2xx                       → Success
 *   410                       → GoneForever
 *   401 403 404 422 other 4xx → Permanent
 *   429 (Too Many Requests)   → Transient (respect Retry-After if set, capped 24h)
 *   5xx                       → Transient
 *   network error / timeout   → Transient
 */
final class FailureClassifier
{
    public function fromStatus(int $status): DeliveryOutcome
    {
        if ($status >= 200 && $status < 300) {
            return DeliveryOutcome::Success;
        }
        if ($status === 410) {
            return DeliveryOutcome::GoneForever;
        }
        if ($status === 429) {
            return DeliveryOutcome::Transient;
        }
        if ($status >= 500 && $status < 600) {
            return DeliveryOutcome::Transient;
        }
        if ($status >= 400 && $status < 500) {
            return DeliveryOutcome::Permanent;
        }
        // 1xx / 3xx are unexpected from an AP inbox; treat as transient
        // so we retry but log.
        return DeliveryOutcome::Transient;
    }

    /**
     * Network/timeouts/TLS errors. Always transient — we don't know
     * if the peer is genuinely dead or just unreachable right now.
     */
    public function fromException(): DeliveryOutcome
    {
        return DeliveryOutcome::Transient;
    }
}
