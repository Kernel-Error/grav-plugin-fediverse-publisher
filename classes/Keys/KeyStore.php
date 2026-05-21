<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Keys;

use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey;
use RuntimeException;

/**
 * Filesystem-backed RSA-2048 keystore. One PEM pair per actor under
 * `<baseDir>/<username>.private.pem` (0600) and
 * `<baseDir>/<username>.public.pem` (0644). See ADR-002 §7-8 and the
 * round-2 amendment R2-2 for the wider context.
 *
 * The class is framework-agnostic — pass it a directory path and a
 * username, that's it. No Grav coupling, so it tests with a tmpdir.
 */
final class KeyStore
{
    private const KEY_BITS = 2048;
    private const USERNAME_PATTERN = '/^[a-z0-9_-]{1,32}$/';

    public function __construct(private readonly string $baseDir)
    {
    }

    /**
     * Generate a fresh RSA-2048 keypair for $username. Refuses to
     * overwrite an existing pair — key rotation is an explicit v1.x
     * feature, not an accidental side-effect.
     */
    public function generate(string $username): KeyPair
    {
        $this->assertValidUsername($username);

        if ($this->exists($username)) {
            throw new RuntimeException(\sprintf(
                "KeyStore: refusing to overwrite existing keypair for '%s'. "
                . 'Delete the .pem files manually to force regeneration.',
                $username,
            ));
        }

        $this->ensureBaseDirExists();

        /** @var PrivateKey $private */
        $private = RSA::createKey(self::KEY_BITS);
        $privatePem = (string) $private;                       // PKCS#8 PEM
        $publicPem  = (string) $private->getPublicKey();       // SPKI PEM

        $this->writeAtomic($this->privatePath($username), $privatePem, 0600);
        $this->writeAtomic($this->publicPath($username), $publicPem, 0644);

        return new KeyPair($username, $publicPem, $privatePem);
    }

    public function load(string $username): KeyPair
    {
        $this->assertValidUsername($username);

        $privatePath = $this->privatePath($username);
        $publicPath  = $this->publicPath($username);

        if (!is_file($privatePath) || !is_file($publicPath)) {
            throw new RuntimeException(\sprintf(
                "KeyStore: no keypair on disk for '%s' (looked under '%s')",
                $username,
                $this->baseDir,
            ));
        }

        $privatePem = (string) file_get_contents($privatePath);
        $publicPem  = (string) file_get_contents($publicPath);

        return new KeyPair($username, $publicPem, $privatePem);
    }

    public function exists(string $username): bool
    {
        $this->assertValidUsername($username);
        return is_file($this->privatePath($username))
            && is_file($this->publicPath($username));
    }

    /**
     * Get-or-create. Convenience for the request-time hot path: the
     * first hit on /activitypub/actor lazily mints the keypair, every
     * subsequent hit reads from disk.
     */
    public function loadOrGenerate(string $username): KeyPair
    {
        if ($this->exists($username)) {
            return $this->load($username);
        }
        return $this->generate($username);
    }

    private function privatePath(string $username): string
    {
        return $this->baseDir . '/' . $username . '.private.pem';
    }

    private function publicPath(string $username): string
    {
        return $this->baseDir . '/' . $username . '.public.pem';
    }

    private function ensureBaseDirExists(): void
    {
        if (is_dir($this->baseDir)) {
            return;
        }
        if (!@mkdir($this->baseDir, 0700, true) && !is_dir($this->baseDir)) {
            throw new RuntimeException(\sprintf(
                "KeyStore: cannot create keys directory '%s'",
                $this->baseDir,
            ));
        }
    }

    /**
     * Write a file via tmp + rename so the destination either contains
     * the full payload or doesn't exist. POSIX rename() is atomic on
     * the same filesystem.
     */
    private function writeAtomic(string $path, string $contents, int $mode): void
    {
        $tmp = $path . '.tmp';

        if (file_put_contents($tmp, $contents, LOCK_EX) !== \strlen($contents)) {
            @unlink($tmp);
            throw new RuntimeException("KeyStore: failed to write '$tmp'");
        }
        if (!chmod($tmp, $mode)) {
            @unlink($tmp);
            throw new RuntimeException("KeyStore: failed to chmod '$tmp'");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("KeyStore: failed to rename '$tmp' to '$path'");
        }
    }

    private function assertValidUsername(string $username): void
    {
        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            throw new RuntimeException(\sprintf(
                "KeyStore: invalid username '%s' (must match %s)",
                $username,
                self::USERNAME_PATTERN,
            ));
        }
    }
}
