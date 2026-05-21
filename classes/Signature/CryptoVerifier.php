<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Signature;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;

/**
 * Final cryptographic step of inbound signature verification.
 *
 * Per the verifier-sketch R3-1 amendment: we do NOT route this through
 * `landrok/activitypub`'s `HttpSignature::verify()`, because that path
 * does its own network fetch which we cannot intercept. Instead we
 * load the PEM ourselves, parse the base64 signature, and let
 * phpseclib3 do the RSA-PKCS1-SHA256 check.
 *
 * phpseclib3's `verify()` is constant-time internally. Class is
 * stateless and framework-agnostic.
 */
final class CryptoVerifier
{
    /**
     * @return bool true iff the signature cryptographically verifies
     *              against the given RSA PEM. Returns false on any
     *              parse / format / unsupported-key-type problem — the
     *              caller maps false → 401. Never throws on the
     *              external-failure path.
     */
    public function verify(string $signingString, string $signatureB64, string $publicPem): bool
    {
        try {
            $key = PublicKeyLoader::load($publicPem);
        } catch (\Throwable) {
            return false;
        }
        if (!$key instanceof RSA) {
            return false;
        }
        $key = $key->withHash('sha256')->withPadding(RSA::SIGNATURE_PKCS1);

        $signature = base64_decode($signatureB64, true);
        if ($signature === false) {
            return false;
        }
        try {
            return $key->verify($signingString, $signature);
        } catch (\Throwable) {
            return false;
        }
    }
}
