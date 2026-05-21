<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\FediversePublisher\Config\HostBaseResolver;
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
use Monolog\Handler\StreamHandler;
use Monolog\Logger as MonologLogger;
use Psr\Log\LoggerInterface;

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

        // hostBase via HostBaseResolver, NOT directly off
        // `$grav['uri']->rootUrl(true)` — in CLI that returns
        // `http://localhost`, which Mastodon refuses as a private-
        // network reference (v0.0.4 production showstopper). The
        // resolver prefers the operator-set
        // `federation.canonical_host`, falls back to rootUrl/
        // HTTP_HOST only in web context.
        $hostBase = HostBaseResolver::resolve(
            configuredCanonical: \is_string($config['federation']['canonical_host'] ?? null)
                ? (string) $config['federation']['canonical_host']
                : '',
            gravRootUrl:         (string) $grav['uri']->rootUrl(true),
            serverHttps:         !empty($_SERVER['HTTPS']),
            serverHost:          (string) ($_SERVER['HTTP_HOST'] ?? ''),
        );
        if (!HostBaseResolver::isPublishable($hostBase)) {
            $this->output->writeln(\sprintf(
                '<error>Resolved hostBase %s is not publishable. Set federation.canonical_host '
                . 'to your public https URL before draining the queue.</error>',
                $hostBase
            ));
            return 1;
        }
        $actorUrl  = $hostBase . '/activitypub/actor';

        $pdo = Database::connect($dbPath);
        Database::migrate($pdo);

        // Logger sourced from Grav, with the same Monolog fallback the
        // web entrypoint uses (fediverse-publisher.php::resolveLogger).
        // NEVER `new NullLogger()` here — that triggers psr/log
        // AbstractLogger autoload, which fatals when the plugin's
        // vendor and Grav's vendor have mismatched psr/log major
        // versions. See RequestSigner's docblock for the full chain.
        $log = $this->resolveLogger($grav);

        $clock     = new SystemClock();
        $signer    = new RequestSigner(new Signer(), $clock, $log);
        $keys      = new KeyStore($keysDir);
        $followers = new FollowerStore($pdo);
        $queue     = new OutboundQueue($pdo);
        $http      = new GuzzleClient([
            'http_errors'     => false,
            'allow_redirects' => false,
        ]);

        $dispatcher = new Dispatcher(
            queue:            $queue,
            signer:           $signer,
            keys:             $keys,
            followers:        $followers,
            retryPolicy:      new RetryPolicy(),
            classifier:       new FailureClassifier(),
            http:             $http,
            log:              $log,
            localActorUrl:    $actorUrl,
            localKeyUsername: $username,
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

    /**
     * Resolve Grav's PSR-3 logger with a Monolog fallback. Mirrors
     * `fediverse-publisher.php::resolveLogger()` so CLI and web paths
     * behave the same way under logger-container hiccups. Both paths
     * end at a real PSR-3 logger; neither path ever instantiates a
     * psr/log `NullLogger`, which would force the conflicting
     * `AbstractLogger` autoload.
     */
    private function resolveLogger(Grav $grav): LoggerInterface
    {
        $candidate = $grav['log'] ?? null;
        if ($candidate instanceof LoggerInterface) {
            return $candidate;
        }
        $locator = $grav['locator'] ?? null;
        $logDir  = ($locator !== null && \method_exists($locator, 'findResource'))
            ? (string) $locator->findResource('log://', true)
            : '';
        if ($logDir === '' && \defined('GRAV_ROOT')) {
            $logDir = \GRAV_ROOT . '/logs';
        }
        $logger = new MonologLogger('fediverse-publisher');
        $logger->pushHandler(new StreamHandler($logDir . '/fediverse-publisher.log', \Monolog\Level::Info));
        return $logger;
    }
}
