<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Storage;

use Grav\Plugin\FediversePublisher\Signature\KeyCache;
use PDO;

/**
 * Thin factory around a PDO connection to the plugin's SQLite database.
 * Per ADR-001 we use WAL mode with sane pragmas and the schema is
 * created idempotently on first connect.
 *
 * The connection itself is held by the plugin entry; this class just
 * owns the recipe.
 */
final class Database
{
    public static function connect(string $path): PDO
    {
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0700, true) && !\is_dir($dir)) {
            throw new \RuntimeException("Database: cannot create $dir");
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE             => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES    => false,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return $pdo;
    }

    public static function migrate(PDO $pdo): void
    {
        (new KeyCache($pdo))->migrate();
        (new InboxLog($pdo))->migrate();
        (new FollowerStore($pdo))->migrate();
        (new \Grav\Plugin\FediversePublisher\Push\OutboundQueue($pdo))->migrate();
    }
}
