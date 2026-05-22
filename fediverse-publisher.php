<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Plugin\FediversePublisher\Actor\ActorBuilder;
use Grav\Plugin\FediversePublisher\Http\ActorController;
use Grav\Plugin\FediversePublisher\Http\BlogPostNegotiator;
use Grav\Plugin\FediversePublisher\Http\FollowersCollectionController;
use Grav\Plugin\FediversePublisher\Http\FollowingCollectionController;
use Grav\Plugin\FediversePublisher\Http\NodeInfoController;
use Grav\Plugin\FediversePublisher\Http\NodeInfoDiscoveryController;
use Grav\Plugin\FediversePublisher\Http\OutboxController;
use Grav\Plugin\FediversePublisher\Http\Router;
use Grav\Plugin\FediversePublisher\Http\WebFingerController;
use Grav\Plugin\FediversePublisher\Inbox\Activities\FollowHandler;
use Grav\Plugin\FediversePublisher\Inbox\Activities\UndoFollowHandler;
use Grav\Plugin\FediversePublisher\Inbox\InboxController;
use Grav\Plugin\FediversePublisher\Keys\KeyStore;
use Grav\Plugin\FediversePublisher\NodeInfo\NodeInfoBuilder;
use Grav\Plugin\FediversePublisher\Outbox\ActivityTransformer;
use Grav\Plugin\FediversePublisher\Outbox\GravPageSource;
use Grav\Plugin\FediversePublisher\Outbox\OutboxBroadcaster;
use Grav\Plugin\FediversePublisher\Outbox\PageRecord;
use Grav\Plugin\FediversePublisher\Config\HostBaseResolver;
use Grav\Plugin\FediversePublisher\Preflight\PreflightCheck;
use Grav\Plugin\FediversePublisher\Push\Dispatcher;
use Grav\Plugin\FediversePublisher\Push\FailureClassifier;
use Grav\Plugin\FediversePublisher\Push\OutboundQueue;
use Grav\Plugin\FediversePublisher\Push\RetryPolicy;
use Grav\Plugin\FediversePublisher\Signature\CryptoVerifier;
use Grav\Plugin\FediversePublisher\Signature\DateChecker;
use Grav\Plugin\FediversePublisher\Signature\DigestChecker;
use Grav\Plugin\FediversePublisher\Signature\KeyCache;
use Grav\Plugin\FediversePublisher\Signature\KeyFetcher;
use Grav\Plugin\FediversePublisher\Signature\KeyProvider;
use Grav\Plugin\FediversePublisher\Signature\RateLimitedLogger;
use Grav\Plugin\FediversePublisher\Signature\RequestSigner;
use Grav\Plugin\FediversePublisher\Signature\Signer;
use Grav\Plugin\FediversePublisher\Signature\SystemClock;
use Grav\Plugin\FediversePublisher\Signature\Verifier;
use Grav\Plugin\FediversePublisher\Storage\Database;
use Grav\Plugin\FediversePublisher\Storage\FollowerStore;
use Grav\Plugin\FediversePublisher\Storage\InboxLog;
use GuzzleHttp\Client as GuzzleClient;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as MonologLogger;
use Psr\Log\LoggerInterface;
use Nyholm\Psr7Server\ServerRequestCreator;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Entry point for the Fediverse Publisher plugin.
 */
class FediversePublisherPlugin extends Plugin
{
    /**
     * Plugin version reported in NodeInfo `software.version`. Bumped
     * in lockstep with the version field in blueprints.yaml.
     */
    private const SOFTWARE_VERSION = '0.0.6';
    private const SOFTWARE_NAME    = 'grav-fediverse-publisher';
    private const HOST_PLATFORM    = 'grav';

    /**
     * Path prefixes our dispatcher is responsible for. Requests outside
     * this set are skipped before any controller is even instantiated,
     * keeping the hot path for normal Grav requests cheap.
     *
     * Stored WITHOUT trailing slashes so the match works for both exact
     * paths (e.g. `/.well-known/webfinger`) and prefix paths (e.g.
     * `/activitypub` matching `/activitypub/actor`).
     *
     * @var list<string>
     */
    private const HANDLED_PREFIXES = [
        '/.well-known/webfinger',
        '/.well-known/nodeinfo',
        '/nodeinfo',
        '/activitypub',
    ];

    private ?PreflightCheck $preflight = null;

    /**
     * @return array<string, mixed>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // Preflight runs early so we can fail fast on missing
            // pdo_sqlite or a subdirectory install. The actual
            // dispatcher fires later (onPagesInitialized) because
            // the Outbox needs Grav's pages collection, which isn't
            // built at onPluginsInitialized time.
            'onPluginsInitialized'   => [['runPreflight', 0]],
            'onPagesInitialized'     => [['onPagesInitialized', 0]],
            'onPageInitialized'      => [['onPageInitialized',  0]],
            'onAdminAfterSave'       => [['onPageSaved', 0]],
            'onSchedulerInitialized' => [['onSchedulerInitialized', 0]],
        ];
    }

    public function onSchedulerInitialized(\RocketTheme\Toolbox\Event\Event $event): void
    {
        if ($this->preflight === null || !$this->preflight->isHealthy()) {
            return;
        }
        $scheduler = $event['scheduler'] ?? null;
        if ($scheduler === null || !\method_exists($scheduler, 'addFunction')) {
            return;
        }
        $self = $this;
        $job = $scheduler->addFunction(
            function () use ($self): void {
                $self->runPushDispatcher();
            },
            [],
            'fediverse-publisher-push',
        );
        if (\method_exists($job, 'at')) {
            $job->at('* * * * *');     // every minute
        }
    }

    /**
     * Drains the push queue. Called from the Grav scheduler tick AND
     * from the `bin/plugin fediverse-publisher push:flush` CLI for
     * dev. Public so the CLI command can invoke it directly.
     */
    public function runPushDispatcher(): array
    {
        return $this->buildDispatcher()->tick();
    }

    /**
     * Fired by Grav admin when a page is saved. Broadcasts the
     * Create activity to every active follower if the page falls
     * under the blog filter and is published.
     */
    public function onPageSaved(\RocketTheme\Toolbox\Event\Event $event): void
    {
        if ($this->preflight === null || !$this->preflight->isHealthy()) {
            return;
        }
        $object = $event['object'] ?? null;
        if ($object === null || !\method_exists($object, 'route')) {
            return;
        }
        // Only act on pages, not on user/config/site saves that share
        // this hook.
        if (!\method_exists($object, 'published') || !\method_exists($object, 'routable')) {
            return;
        }
        if (!$object->published() || !$object->routable()) {
            return;
        }

        $hostBase  = $this->resolveHostBase();
        $configArr = (array) $this->config->get('plugins.fediverse-publisher', []);
        $pages = new GravPageSource(
            $this->grav['pages'],
            $this->configStr($configArr, 'blog.path_filter') ?: '/blog/**',
            $hostBase,
        );
        $record = $pages->findByRoute((string) $object->route());
        if ($record === null) {
            return;     // not under our blog filter
        }

        $this->buildBroadcaster()->broadcast($record);
    }

    /**
     * Composer autoload. Grav core calls this on every plugin during
     * boot and registers the returned ClassLoader via setAutoloader().
     * MUST be public.
     *
     * Wrapped in try/catch so a broken vendor/ doesn't take the host
     * site down — Grav fires this method on every plugin during boot
     * regardless of the `enabled` flag, so anything that throws here
     * propagates straight out to HTTP 500. The production-deploy
     * feedback for v0.0.1 documented exactly this footgun
     * (`vendor/psr/log` colliding with Grav 1.7's bundled copy). The
     * plugin is now no-op if its own vendor fails to load.
     */
    public function autoload(): ?ClassLoader
    {
        try {
            $autoload = __DIR__ . '/vendor/autoload.php';
            if (!\is_file($autoload)) {
                return null;
            }
            $loader = require $autoload;
            if (!$loader instanceof ClassLoader) {
                return null;
            }
            // Composer's generated autoload.php registers the loader
            // with prepend=true. That puts the plugin's vendor in
            // front of Grav's vendor for any class both define —
            // most importantly `Psr\Log\*`. The plugin pins psr/log
            // ^1.1 (Grav 1.7 compat), Grav 2.0 ships v3. With prepend
            // we serve v1 AbstractLogger against an in-memory v3
            // LoggerInterface and PHP fatals.
            //
            // Re-register with prepend=false so the host Grav vendor
            // wins for shared classes; our plugin-exclusive classes
            // (landrok, phpseclib, nyholm, ...) still resolve via
            // PSR-4 normally because Grav doesn't ship them. This is
            // the layered defense behind RequestSigner's null-default-
            // logger trick; the autoloader change is the durable fix,
            // the null-default is the belt.
            //
            // See review-notes-codex-2026-05-21-round4.md for the
            // call-out that surfaced this.
            $loader->unregister();
            $loader->register(false);
            return $loader;
        } catch (\Throwable $e) {
            \error_log('fediverse-publisher: autoload failed, plugin disabled: ' . $e->getMessage());
            return null;
        }
    }

    public function runPreflight(): void
    {
        try {
            // Load PreflightCheck via an explicit require — falling back
            // here means our own vendor/ failed to load (autoload returned
            // null), in which case the PSR-4 autoloader wouldn't resolve
            // the class. We still want to be able to surface a useful
            // error to the admin, not just 500.
            if (!\class_exists(PreflightCheck::class, false)) {
                require_once __DIR__ . '/classes/Config/HostBaseResolver.php';
                require_once __DIR__ . '/classes/Preflight/PreflightCheck.php';
            }

            $this->preflight = new PreflightCheck(
                hasPdoSqlite:      \extension_loaded('pdo_sqlite'),
                baseUrlPath:       $this->resolveBaseUrlPath(),
                resolvedHostBase:  $this->resolveHostBase(),
            );

            if (!$this->preflight->isHealthy()) {
                $this->emitAdminNotices($this->preflight->getErrors());
            }
        } catch (\Throwable $e) {
            // Last-resort guard: a bug here must never take the site
            // down. The plugin silently degrades to a no-op; the error
            // lands in the PHP-FPM error log for the operator.
            \error_log('fediverse-publisher: preflight crashed, plugin disabled: ' . $e->getMessage());
            $this->preflight = null;
        }
    }

    public function onPagesInitialized(): void
    {
        if ($this->preflight === null || !$this->preflight->isHealthy()) {
            return;
        }

        // Fast bail: only engage if the request path is something we
        // own. Every other Grav request (admin, pages, assets) passes
        // through untouched.
        $path = $this->currentRequestPath();
        if (!$this->isHandledPath($path)) {
            return;
        }

        try {
            $response = $this->buildRouter()->dispatch($this->currentRequest());
            if ($response !== null) {
                $this->grav->close($response);
            }
        } catch (\Throwable $e) {
            // A bug in our dispatcher must not take Grav's normal page
            // rendering down. Log and bail; the user gets the regular
            // page (which is unlikely to be useful for an AP request,
            // but better than HTTP 500).
            \error_log('fediverse-publisher: dispatcher crashed: ' . $e->getMessage());
        }
    }

    private function buildRouter(): Router
    {
        $hostBase   = $this->resolveHostBase();
        $localHost  = (string) \parse_url($hostBase, PHP_URL_HOST);
        $config     = $this->config->get('plugins.fediverse-publisher', []);
        $configArr  = \is_array($config) ? $config : [];

        $keys       = new KeyStore($this->resolveKeysDir());
        $actor      = new ActorBuilder($keys, $hostBase, $configArr);

        $webfinger  = new WebFingerController($actor, $localHost);
        $actorCtrl  = new ActorController($actor);

        $nodeInfo   = new NodeInfoBuilder(
            softwareName:    self::SOFTWARE_NAME,
            softwareVersion: self::SOFTWARE_VERSION,
            hostPlatform:    self::HOST_PLATFORM,
            hostVersion:     $this->resolveGravVersion(),
            isConfigured:    $actor->isConfigured(),
            nodeName:        $this->configStr($configArr, 'actor.name'),
            nodeDescription: $this->configStr($configArr, 'actor.summary'),
        );
        $nodeInfoDiscovery = new NodeInfoDiscoveryController($nodeInfo, $hostBase);
        $nodeInfoCtrl      = new NodeInfoController($nodeInfo);

        $outboxCtrl = $this->buildOutboxController($hostBase, $configArr, $actor);
        $inboxCtrl  = $this->buildInboxController($hostBase, $configArr, $actor);

        // Followers/following collections — the actor JSON-LD promises
        // these URLs. Without handlers Mastodon's profile-resolution gets
        // a 404 and renders "0 followers" regardless of real state.
        $pdo            = Database::connect($this->resolveDatabasePath());
        Database::migrate($pdo);
        $followerStore  = new FollowerStore($pdo);
        $followersCtrl  = new FollowersCollectionController(
            followers:    $followerStore,
            followersUrl: $hostBase . '/activitypub/followers',
        );
        $followingCtrl  = new FollowingCollectionController(
            followingUrl: $hostBase . '/activitypub/following',
        );

        $router = new Router();
        $router->get('/.well-known/webfinger',  [$webfinger,        'handle']);
        $router->get('/.well-known/nodeinfo',   [$nodeInfoDiscovery,'handle']);
        $router->get('/nodeinfo/2.0',           [$nodeInfoCtrl,     'handle']);
        $router->get('/activitypub/actor',      [$actorCtrl,        'handle']);
        $router->get('/activitypub/outbox',     [$outboxCtrl,       'handle']);
        $router->get('/activitypub/followers',  [$followersCtrl,    'handle']);
        $router->get('/activitypub/following',  [$followingCtrl,    'handle']);
        $router->post('/activitypub/inbox',     [$inboxCtrl,        'handle']);

        return $router;
    }

    /**
     * @param array<string, mixed> $configArr
     */
    private function buildInboxController(string $hostBase, array $configArr, ActorBuilder $actor): InboxController
    {
        $pdo = Database::connect($this->resolveDatabasePath());
        Database::migrate($pdo);

        $clock     = new SystemClock();
        $rateLog   = new RateLimitedLogger($this->resolveLogger(), $clock);
        $http      = new GuzzleClient([
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
        $resolver  = static function (string $host): array {
            $records = @\dns_get_record($host, DNS_A | DNS_AAAA);
            if ($records === false) {
                return [];
            }
            $ips = [];
            foreach ($records as $rec) {
                if (isset($rec['ip']))    { $ips[] = $rec['ip']; }
                if (isset($rec['ipv6']))  { $ips[] = $rec['ipv6']; }
            }
            return $ips;
        };
        $allowCidrs = $configArr['federation']['dev_allow_cidrs'] ?? [];
        if (!\is_array($allowCidrs)) {
            $allowCidrs = [];
        }
        $userAgent = \sprintf(
            'grav-plugin-fediverse-publisher/%s (+%s/)',
            self::SOFTWARE_VERSION,
            $hostBase,
        );
        $fetcher   = new KeyFetcher(
            $http,
            $clock,
            $resolver,
            \array_values(\array_filter($allowCidrs, '\\is_string')),
            $userAgent,
        );
        $keyCache  = new KeyCache($pdo);
        $keys      = new KeyProvider($keyCache, $fetcher, $rateLog, $clock);

        $verifier  = new Verifier(
            keys:    $keys,
            dates:   new DateChecker($clock),
            digests: new DigestChecker(),
            crypto:  new CryptoVerifier(),
            log:     new InboxLog($pdo),
            rateLog: $rateLog,
        );

        $followers = new FollowerStore($pdo);
        $queue     = new OutboundQueue($pdo);

        return new InboxController(
            verifier:       $verifier,
            followHandler:  new FollowHandler($followers, $queue, $actor->actorUrl()),
            undoHandler:    new UndoFollowHandler($followers, $actor->actorUrl()),
        );
    }

    private function buildDispatcher(): Dispatcher
    {
        $hostBase   = $this->resolveHostBase();
        $configArr  = (array) $this->config->get('plugins.fediverse-publisher', []);
        $pdo        = Database::connect($this->resolveDatabasePath());
        Database::migrate($pdo);
        $clock      = new SystemClock();
        $signer     = new RequestSigner(new Signer(), $clock, $this->resolveLogger());
        $keys       = new KeyStore($this->resolveKeysDir());
        $followers  = new FollowerStore($pdo);
        $queue      = new OutboundQueue($pdo);
        $http       = new GuzzleClient([
            'http_errors'     => false,
            'allow_redirects' => false,
        ]);
        $username = $this->configStr($configArr, 'actor.username');

        return new Dispatcher(
            queue:            $queue,
            signer:           $signer,
            keys:             $keys,
            followers:        $followers,
            retryPolicy:      new RetryPolicy(),
            classifier:       new FailureClassifier(),
            http:             $http,
            log:              $this->resolveLogger(),
            localActorUrl:    $hostBase . '/activitypub/actor',
            localKeyUsername: $username,
        );
    }

    private function buildBroadcaster(): OutboxBroadcaster
    {
        $hostBase  = $this->resolveHostBase();
        $configArr = (array) $this->config->get('plugins.fediverse-publisher', []);
        $pdo       = Database::connect($this->resolveDatabasePath());
        Database::migrate($pdo);

        $followers   = new FollowerStore($pdo);
        $queue       = new OutboundQueue($pdo);
        $transformer = new ActivityTransformer(
            actorUrl:     $hostBase . '/activitypub/actor',
            followersUrl: $hostBase . '/activitypub/followers',
            tagBaseUrl:   $this->resolveTagBaseUrl($hostBase, $configArr),
        );

        return new OutboxBroadcaster(
            followers:      $followers,
            queue:          $queue,
            transformer:    $transformer,
            localActorUrl:  $hostBase . '/activitypub/actor',
            noteThreshold:  $this->configInt($configArr, 'blog.note_threshold', 1000),
            log:            $this->resolveLogger(),
        );
    }

    /**
     * Derive the base URL for Hashtag `href` attributes from the
     * configured `blog.path_filter`. Default filter `/blog/**` →
     * `<host>/blog`. Grav's stock blog-skeleton tag-listing URL is
     * `<blog-root>/tag:<name>` so the `Hashtag.href` we emit there
     * resolves to the canonical per-tag landing page on the source
     * site. Operators with custom path filters get the prefix that
     * matches their setup automatically.
     *
     * @param array<string, mixed> $configArr
     */
    private function resolveTagBaseUrl(string $hostBase, array $configArr): string
    {
        $filter = $this->configStr($configArr, 'blog.path_filter') ?: '/blog/**';
        $prefix = (string) \preg_replace('#/\*\*?$#', '', $filter);
        return rtrim($hostBase, '/') . '/' . trim($prefix, '/');
    }

    private function resolveLogger(): LoggerInterface
    {
        $candidate = $this->grav['log'] ?? null;
        if ($candidate instanceof LoggerInterface) {
            return $candidate;
        }
        // Fall back to a Monolog writing to our own log file.
        $logDir = (string) ($this->grav['locator']->findResource('log://', true) ?? GRAV_ROOT . '/logs');
        $logger = new MonologLogger('fediverse-publisher');
        $logger->pushHandler(new StreamHandler($logDir . '/fediverse-publisher.log', \Monolog\Level::Info));
        return $logger;
    }

    private function resolveDatabasePath(): string
    {
        $locator = $this->grav['locator'] ?? null;
        if ($locator !== null && \method_exists($locator, 'findResource')) {
            $userData = (string) $locator->findResource('user-data://', true);
            if ($userData !== '') {
                return $userData . '/fediverse-publisher/fediverse-publisher.sqlite';
            }
        }
        return GRAV_ROOT . '/user/data/fediverse-publisher/fediverse-publisher.sqlite';
    }

    /**
     * @param array<string, mixed> $configArr
     */
    private function buildOutboxController(string $hostBase, array $configArr, ActorBuilder $actor): OutboxController
    {
        $transformer = new ActivityTransformer(
            actorUrl:     $actor->actorUrl(),
            followersUrl: $hostBase . '/activitypub/followers',
            tagBaseUrl:   $this->resolveTagBaseUrl($hostBase, $configArr),
        );
        $pages = new GravPageSource(
            $this->grav['pages'],
            $this->configStr($configArr, 'blog.path_filter') ?: '/blog/**',
            $hostBase,
        );
        return new OutboxController(
            pages:          $pages,
            transformer:    $transformer,
            outboxUrl:      $hostBase . '/activitypub/outbox',
            noteThreshold:  $this->configInt($configArr, 'blog.note_threshold', 1000),
        );
    }

    /**
     * Content negotiation on blog-post URLs (ADR-004 §2). If a peer
     * fetches `/blog/<post>` with an AP-flavoured `Accept` header, we
     * serve a Note/Article object instead of letting Grav render the
     * HTML page. Otherwise we silently no-op.
     */
    public function onPageInitialized(): void
    {
        if ($this->preflight === null || !$this->preflight->isHealthy()) {
            return;
        }

        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        if ($accept === '') {
            return;
        }

        try {
            $this->doPageContentNegotiation($accept);
        } catch (\Throwable $e) {
            \error_log('fediverse-publisher: content negotiation crashed: ' . $e->getMessage());
        }
    }

    private function doPageContentNegotiation(string $accept): void
    {

        $configArr = (array) $this->config->get('plugins.fediverse-publisher', []);
        $hostBase  = $this->resolveHostBase();

        $pages = new GravPageSource(
            $this->grav['pages'],
            $this->configStr($configArr, 'blog.path_filter') ?: '/blog/**',
            $hostBase,
        );

        $page = $this->grav['page'] ?? null;
        if ($page === null || !\method_exists($page, 'route')) {
            return;
        }
        $record = $pages->findByRoute((string) $page->route());
        if ($record === null) {
            return;
        }

        $keys = new KeyStore($this->resolveKeysDir());
        $actor = new ActorBuilder($keys, $hostBase, $configArr);
        $transformer = new ActivityTransformer(
            actorUrl:     $actor->actorUrl(),
            followersUrl: $hostBase . '/activitypub/followers',
            tagBaseUrl:   $this->resolveTagBaseUrl($hostBase, $configArr),
        );
        $negotiator = new BlogPostNegotiator(
            transformer:   $transformer,
            noteThreshold: $this->configInt($configArr, 'blog.note_threshold', 1000),
        );

        if (!$negotiator->acceptsActivityPub($accept)) {
            return;
        }

        $this->grav->close($negotiator->buildResponse($record));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configInt(array $config, string $dottedKey, int $default): int
    {
        $value = $config;
        foreach (\explode('.', $dottedKey) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && \ctype_digit(\ltrim($value, '-'))) {
            return (int) $value;
        }
        return $default;
    }

    private function resolveGravVersion(): string
    {
        if (\defined('GRAV_VERSION')) {
            return (string) \GRAV_VERSION;
        }
        return 'unknown';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configStr(array $config, string $dottedKey): string
    {
        $value = $config;
        foreach (\explode('.', $dottedKey) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return '';
            }
            $value = $value[$segment];
        }
        return \is_string($value) ? \trim($value) : '';
    }

    private function resolveHostBase(): string
    {
        $configArr = (array) $this->config->get('plugins.fediverse-publisher', []);
        $gravRootUrl = '';
        $uri = $this->grav['uri'] ?? null;
        if ($uri !== null && \method_exists($uri, 'rootUrl')) {
            $gravRootUrl = (string) $uri->rootUrl(true);
        }
        return HostBaseResolver::resolve(
            configuredCanonical: $this->configStr($configArr, 'federation.canonical_host'),
            gravRootUrl:         $gravRootUrl,
            serverHttps:         !empty($_SERVER['HTTPS']),
            serverHost:          (string) ($_SERVER['HTTP_HOST'] ?? ''),
        );
    }

    private function resolveKeysDir(): string
    {
        $locator = $this->grav['locator'] ?? null;
        if ($locator !== null && \method_exists($locator, 'findResource')) {
            $userData = (string) $locator->findResource('user-data://', true);
            if ($userData !== '') {
                return $userData . '/fediverse-publisher/keys';
            }
        }
        // Hard fallback against the Grav root in the rare case the
        // locator isn't available yet.
        return GRAV_ROOT . '/user/data/fediverse-publisher/keys';
    }

    private function resolveBaseUrlPath(): string
    {
        $uri = $this->grav['uri'] ?? null;
        if ($uri === null || !\method_exists($uri, 'rootUrl')) {
            return '';
        }
        $rootUrl = (string) $uri->rootUrl(false);
        return (string) \parse_url($rootUrl, PHP_URL_PATH);
    }

    private function currentRequestPath(): string
    {
        return (string) \parse_url(
            $_SERVER['REQUEST_URI'] ?? '/',
            PHP_URL_PATH,
        );
    }

    private function isHandledPath(string $path): bool
    {
        foreach (self::HANDLED_PREFIXES as $prefix) {
            if ($path === $prefix || \str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }

    private function currentRequest(): ServerRequestInterface
    {
        // Prefer Grav's own PSR-7 ServerRequest if it's actually PSR-7.
        $injected = $this->grav['request'] ?? null;
        if ($injected instanceof ServerRequestInterface) {
            return $injected;
        }

        // Otherwise rebuild from globals via Nyholm — works on any SAPI.
        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
        return $creator->fromGlobals();
    }

    /**
     * @param list<string> $errors
     */
    private function emitAdminNotices(array $errors): void
    {
        $messages = $this->grav['messages'] ?? null;
        if ($messages === null) {
            return;
        }
        foreach ($errors as $message) {
            $messages->add('Fediverse Publisher: ' . $message, 'error');
        }
    }
}
