<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Signature;

use DateTimeImmutable;

/**
 * Immutable result of a successful actor-key fetch (or load from
 * cache). The whole record is what the verifier needs: the PEM for
 * crypto, the canonical owner-URL for identity binding, optionally
 * the discovered inbox URL so FollowHandler doesn't need a second
 * round-trip.
 */
final class FetchedKey
{
    public function __construct(
        public readonly string $keyId,                 // canonical key URL (fragment preserved)
        public readonly string $ownerUrl,              // canonical owner URL (no fragment)
        public readonly string $pem,                   // RSA public PEM
        public readonly string $inboxUrl,              // remote actor's `inbox`
        public readonly ?string $sharedInboxUrl,
        public readonly DateTimeImmutable $fetchedAt,
    ) {
    }
}
