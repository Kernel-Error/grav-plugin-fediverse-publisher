<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\FediversePublisher\Keys\KeyStore;
use Grav\Plugin\FediversePublisher\Push\Dispatcher;
use Grav\Plugin\FediversePublisher\Push\FailureClassifier;
use Grav\Plugin\FediversePublisher\Push\OutboundQueue;
use Grav\Plugin\FediversePublisher\Push\RetryPolicy;
use Grav\Plugin\FediversePublisher\Signature\RequestSigner;
use Grav\Plugin\FediversePublisher\Signature\Signer;
use Grav\Plugin\FediversePublisher\Signature\SystemClock;
use Grav\Plugin\FediversePublisher\Storage\Database;
use Grav\Plugin\FediversePublisher\Storage\FollowerStore;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Log\NullLogger;

/**
 * `bin/plugin fediverse-publisher flush-queue`
 *
 * Drains the outbound push queue in one tick — synchronous variant of
 * what the Grav scheduler does every minute. Useful for dev / smoke
 * testing without waiting for the next scheduler tick.
 *
 * Builds the Dispatcher independently of the plugin's
 * `onPluginsInitialized` wiring (which doesn't fire in CLI mode the
 * same way as in web requests), so the command works even when the
 * web side isn't currently serving requests.
 */
final class FlushQueueCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('flush-queue')
            ->setDescription('Drain the fediverse-publisher outbound push queue (one tick).');
    }

    protected function serve(): int
    {
        require_once \dirname(__DIR__) . '/vendor/autoload.php';

        $grav   = Grav::instance();
        $config = (array) $grav['config']->get('plugins.fediverse-publisher', []);
        if (!\extension_loaded('pdo_sqlite')) {
            $this->output->writeln('<error>pdo_sqlite extension not loaded; plugin cannot run.</error>');
            return 1;
        }

        $username = \is_string($config['actor']['username'] ?? null) ? \trim($config['actor']['username']) : '';
        if ($username === '') {
            $this->output->writeln('<error>actor.username is not configured.</error>');
            return 1;
        }

        $locator   = $grav['locator'];
        $userData  = (string) $locator->findResource('user-data://', true);
        $dbPath    = $userData . '/fediverse-publisher/fediverse-publisher.sqlite';
        $keysDir   = $userData . '/fediverse-publisher/keys';
        $hostBase  = \rtrim((string) $grav['uri']->rootUrl(true), '/');
        $actorUrl  = $hostBase . '/activitypub/actor';

        $pdo = Database::connect($dbPath);
        Database::migrate($pdo);

        $clock     = new SystemClock();
        $signer    = new RequestSigner(new Signer(), $clock);
        $keys      = new KeyStore($keysDir);
        $followers = new FollowerStore($pdo);
        $queue     = new OutboundQueue($pdo);
        $http      = new GuzzleClient([
            'http_errors'     => false,
            'allow_redirects' => false,
        ]);

        $allowCidrs = $config['federation']['dev_allow_cidrs'] ?? [];
        $allowCidrs = \is_array($allowCidrs)
            ? \array_values(\array_filter($allowCidrs, '\\is_string'))
            : [];

        $dispatcher = new Dispatcher(
            queue:                 $queue,
            signer:                $signer,
            keys:                  $keys,
            followers:             $followers,
            retryPolicy:           new RetryPolicy(),
            classifier:            new FailureClassifier(),
            http:                  $http,
            clock:                 $clock,
            log:                   new NullLogger(),
            localActorUrl:         $actorUrl,
            localKeyUsername:      $username,
            allowedReservedCidrs:  $allowCidrs,
        );

        $counts = $dispatcher->tick();

        $this->output->writeln(\sprintf(
            'processed=%d  success=%d  retried=%d  dead=%d  stale=%d',
            $counts['processed'] ?? 0,
            $counts['success']   ?? 0,
            $counts['retried']   ?? 0,
            $counts['dead']      ?? 0,
            $counts['stale']     ?? 0,
        ));
        return 0;
    }
}
