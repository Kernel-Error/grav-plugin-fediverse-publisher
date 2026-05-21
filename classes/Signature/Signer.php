<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Signature;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;

/**
 * Outbound HTTP-signature signer — symmetric to `CryptoVerifier`.
 *
 * Per ADR-002 §2 and verifier-sketch R3-1: we do NOT route through
 * landrok or any other ActivityPub library on the outbound path. The
 * algorithm is small enough (~80 lines including this class and
 * `RequestSigner`) that owning it directly avoids the network-I/O
 * surprises that prompted the round-3 amendment.
 *
 * Signs the standard draft-cavage-12 signing string against an RSA
 * PEM with `SHA256withRSA` + PKCS#1 v1.5 padding. Returns the
 * base64-encoded signature for inclusion in the `Signature` header.
 * Stateless, no I/O.
 */
final class Signer
{
    /**
     * @throws \RuntimeException if the PEM cannot be loaded or signed
     *                            against (caller treats this as a hard
     *                            error — pushing without a valid key
     *                            shouldn't be possible if the install
     *                            preflight passed).
     */
    public function sign(string $signingString, string $privatePem): string
    {
        $key = PublicKeyLoader::load($privatePem);
        if (!$key instanceof RSA) {
            throw new \RuntimeException('Signer: only RSA keys are supported');
        }
        $key = $key->withHash('sha256')->withPadding(RSA::SIGNATURE_PKCS1);
        $sig = $key->sign($signingString);
        if ($sig === '' || $sig === false) {
            throw new \RuntimeException('Signer: signing returned empty result');
        }
        return base64_encode($sig);
    }
}
