<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Keys;

use Grav\Plugin\FediversePublisher\Keys\KeyStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KeyStoreTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/fpub-keystore-' . \bin2hex(\random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->tmpDir)) {
            $this->rrmdir($this->tmpDir);
        }
    }

    public function testGenerateProducesBothPemFilesWithCorrectModes(): void
    {
        $store = new KeyStore($this->tmpDir);
        $pair = $store->generate('blog');

        self::assertSame('blog', $pair->username);
        self::assertStringContainsString('BEGIN PUBLIC KEY', $pair->publicPem);
        self::assertStringContainsString('BEGIN PRIVATE KEY', $pair->privatePem);

        $privatePath = $this->tmpDir . '/blog.private.pem';
        $publicPath  = $this->tmpDir . '/blog.public.pem';
        self::assertFileExists($privatePath);
        self::assertFileExists($publicPath);

        $privateMode = \fileperms($privatePath) & 0777;
        $publicMode  = \fileperms($publicPath) & 0777;
        self::assertSame(0600, $privateMode, 'private key must be 0600');
        self::assertSame(0644, $publicMode,  'public key must be 0644');
    }

    public function testGeneratedKeyIsRsa2048(): void
    {
        $store = new KeyStore($this->tmpDir);
        $pair = $store->generate('blog');

        $key = \openssl_pkey_get_public($pair->publicPem);
        self::assertNotFalse($key, 'public PEM must parse via openssl');

        $details = \openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertSame(OPENSSL_KEYTYPE_RSA, $details['type']);
        self::assertSame(2048, $details['bits']);
    }

    public function testRoundtripReturnsSamePems(): void
    {
        $store = new KeyStore($this->tmpDir);
        $generated = $store->generate('blog');
        $loaded = $store->load('blog');

        self::assertSame($generated->publicPem,  $loaded->publicPem);
        self::assertSame($generated->privatePem, $loaded->privatePem);
    }

    public function testGenerateRefusesToOverwrite(): void
    {
        $store = new KeyStore($this->tmpDir);
        $store->generate('blog');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refusing to overwrite');
        $store->generate('blog');
    }

    public function testLoadOrGenerateIsIdempotent(): void
    {
        $store = new KeyStore($this->tmpDir);
        $first  = $store->loadOrGenerate('blog');
        $second = $store->loadOrGenerate('blog');

        self::assertSame($first->publicPem,  $second->publicPem);
        self::assertSame($first->privatePem, $second->privatePem);
    }

    public function testExistsTrueOnlyIfBothFilesPresent(): void
    {
        $store = new KeyStore($this->tmpDir);
        self::assertFalse($store->exists('blog'));

        $store->generate('blog');
        self::assertTrue($store->exists('blog'));

        \unlink($this->tmpDir . '/blog.public.pem');
        self::assertFalse($store->exists('blog'));
    }

    public function testLoadOnMissingThrows(): void
    {
        $store = new KeyStore($this->tmpDir);
        $this->expectException(RuntimeException::class);
        $store->load('blog');
    }

    public function testRejectsInvalidUsernames(): void
    {
        $store = new KeyStore($this->tmpDir);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid username');
        $store->generate('../etc/passwd');
    }

    public function testKeysDirectoryIsCreatedOnFirstGenerate(): void
    {
        self::assertDirectoryDoesNotExist($this->tmpDir);
        $store = new KeyStore($this->tmpDir);
        $store->generate('blog');
        self::assertDirectoryExists($this->tmpDir);
    }

    private function rrmdir(string $path): void
    {
        foreach ((array) \glob($path . '/*') as $f) {
            \is_dir($f) ? $this->rrmdir($f) : \unlink($f);
        }
        \rmdir($path);
    }
}
