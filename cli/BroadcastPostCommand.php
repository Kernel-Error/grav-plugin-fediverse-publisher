<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\FediversePublisher\Config\HostBaseResolver;
use Grav\Plugin\FediversePublisher\Outbox\ActivityTransformer;
use Grav\Plugin\FediversePublisher\Outbox\GravPageSource;
use Grav\Plugin\FediversePublisher\Outbox\OutboxBroadcaster;
use Grav\Plugin\FediversePublisher\Push\OutboundQueue;
use Grav\Plugin\FediversePublisher\Storage\Database;
use Grav\Plugin\FediversePublisher\Storage\FollowerStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputArgument;

/**
 * `bin/plugin fediverse-publisher broadcast:post <route>`
 *
 * Manually enqueue a `Create` activity for an existing blog post.
 * Operator recovery tool for posts that were saved before the
 * v0.0.7 flex-save-event fix landed and therefore never reached
 * the queue automatically. Subsequent `flush-queue` ticks (or the
 * scheduler) deliver the enqueued Create to all active followers.
 *
 * Idempotent on (activity_id, recipient_inbox) per the OutboundQueue
 * UNIQUE constraint, so running this twice for the same route is
 * harmless — the second run no-ops at enqueue time.
 */
final class BroadcastPostCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('broadcast:post')
            ->addArgument(
                'route',
                InputArgument::REQUIRED,
                'Page route to broadcast, e.g. /blog/my-post'
            )
            ->setDescription('Enqueue a Create activity for an existing blog post (manual recovery).');
    }

    protected function serve(): int
    {
        require_once \dirname(__DIR__) . '/vendor/autoload.php';

        $grav   = Grav::instance();
        // Grav's CLI doesn't walk the pages tree on its own — the
        // pages collection is lazy-built only when the full
        // bootstrap touches it. Force it via init() so
        // findByRoute() sees the same tree the web path does;
        // without this, the CLI thinks the page doesn't exist
        // even though the markdown file is on disk.
        if (\method_exists($grav['pages'], 'init')) {
            $grav['pages']->init();
        }
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

        $route = (string) $this->input->getArgument('route');
        if ($route === '' || !\str_starts_with($route, '/')) {
            $this->output->writeln('<error>Route must be an absolute Grav route, e.g. /blog/my-post.</error>');
            return 1;
        }

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
                . 'to your public https URL before broadcasting.</error>',
                $hostBase
            ));
            return 1;
        }

        $log = $this->resolveLogger($grav);

        $blogFilter = \is_string($config['blog']['path_filter'] ?? null)
            ? (string) $config['blog']['path_filter']
            : '/blog/**';
        $tagBaseUrl = rtrim($hostBase, '/') . '/'
            . trim((string) \preg_replace('#/\*\*?$#', '', $blogFilter), '/');

        $pages = new GravPageSource($grav['pages'], $blogFilter, $hostBase);

        // Resolve via Grav's pages collection FIRST so we can tell
        // the operator whether the route even exists. Then hand the
        // PageInterface to findByPage() which itemises which
        // federatability check rejected it. The v0.0.8 deploy spent
        // an hour trying to figure out which of three possible
        // reasons explained the "no federatable page" error — this
        // makes the answer one line.
        $page = $grav['pages']->find($route);
        if (!$page instanceof \Grav\Common\Page\Interfaces\PageInterface) {
            $this->output->writeln(\sprintf(
                '<error>No page at route %s — Grav\'s page tree has no entry for that path. '
                . 'Either the markdown file is missing, the path is mistyped, or the page cache '
                . 'is stale (try `bin/grav clearcache --all`).</error>',
                $route
            ));
            return 1;
        }

        $result = $pages->findByPage($page);
        if (\is_string($result)) {
            $reason = match ($result) {
                'not_under_prefix'           => "route {$route} is not under the configured blog filter ({$blogFilter})",
                'not_published_or_routable'  => "page is not published or not routable",
                'is_listing'                 => "page is a listing (has child pages) — listing pages are filtered out by design",
                'empty_content'              => "page has no rendered content (empty body after HTML strip)",
                default                      => "unrecognized federatability bail ({$result})",
            };
            $this->output->writeln(\sprintf(
                '<error>Page %s exists in the tree but is not federatable: %s.</error>',
                $route,
                $reason
            ));
            return 1;
        }
        $record = $result;

        $locator  = $grav['locator'];
        $userData = (string) $locator->findResource('user-data://', true);
        $dbPath   = $userData . '/fediverse-publisher/fediverse-publisher.sqlite';

        $pdo = Database::connect($dbPath);
        Database::migrate($pdo);

        $followers = new FollowerStore($pdo);
        $queue     = new OutboundQueue($pdo);
        $transformer = new ActivityTransformer(
            actorUrl:     $hostBase . '/activitypub/actor',
            followersUrl: $hostBase . '/activitypub/followers',
            tagBaseUrl:   $tagBaseUrl,
        );
        $broadcaster = new OutboxBroadcaster(
            followers:     $followers,
            queue:         $queue,
            transformer:   $transformer,
            localActorUrl: $hostBase . '/activitypub/actor',
            noteThreshold: \is_int($config['blog']['note_threshold'] ?? null)
                ? (int) $config['blog']['note_threshold']
                : 1000,
            log:           $log,
        );

        $broadcaster->broadcast($record);

        $this->output->writeln(\sprintf(
            '<info>Broadcast enqueued for %s. Run `bin/plugin fediverse-publisher flush-queue` '
            . '(or wait for the scheduler tick) to deliver to followers.</info>',
            $route
        ));
        return 0;
    }

    /**
     * Same as `FlushQueueCommand::resolveLogger()` — Grav's PSR-3
     * logger with a Monolog file-fallback when the container slot
     * is unavailable. Never returns a psr/log `NullLogger`, which
     * would trigger the AbstractLogger autoload conflict between
     * the plugin's v1 and Grav 2.0's v3 (see RequestSigner
     * docblock for the full chain).
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
        $logger = new \Monolog\Logger('fediverse-publisher');
        $logger->pushHandler(new \Monolog\Handler\StreamHandler($logDir . '/fediverse-publisher.log', \Monolog\Level::Info));
        return $logger;
    }
}
