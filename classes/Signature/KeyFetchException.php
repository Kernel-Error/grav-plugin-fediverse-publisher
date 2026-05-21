<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Signature;

use RuntimeException;

/**
 * Raised by KeyFetcher on any rejection (SSRF guard hit, oversized
 * body, parse failure, upstream non-200, etc.). The caller catches it,
 * writes a negative-cache entry, and maps to 401 for the inbox POST.
 */
final class KeyFetchException extends RuntimeException
{
}
