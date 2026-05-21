<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Push;

/**
 * Categorical outcome of a single push delivery attempt. The
 * dispatcher uses this to decide what to do with the queue row.
 *
 * Per ADR-003 §"HTTP failure semantics" + R2-2 (stale-follower rule).
 */
enum DeliveryOutcome: string
{
    /** 2xx — delete the queue row. */
    case Success = 'success';

    /** 410 Gone — delete queue row AND mark follower as stale. */
    case GoneForever = 'gone';

    /** 5xx, 429, network errors, timeouts — keep row, schedule retry. */
    case Transient = 'transient';

    /** 401, 403, 422 etc — permanent fail for this delivery; dead-letter the row but don't touch the follower. */
    case Permanent = 'permanent';

    /** Exhausted MAX_ATTEMPTS — dead-letter the row. */
    case Exhausted = 'exhausted';
}
