<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Keys;

/**
 * Immutable value object holding the PEM representations of an actor's
 * RSA keypair. Carrying both lets callers serve the public key in the
 * Actor JSON-LD and sign outbound requests without re-reading the
 * filesystem.
 */
final class KeyPair
{
    public function __construct(
        public readonly string $username,
        public readonly string $publicPem,
        public readonly string $privatePem,
    ) {
    }
}
