<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Preflight;

use Grav\Plugin\FediversePublisher\Config\HostBaseResolver;

/**
 * Activation pre-flight: refuse to run if the environment cannot host an
 * ActivityPub plugin correctly. The non-negotiable conditions are
 * documented in ADR-001 A-2 (pdo_sqlite must exist), ADR-004 A-4
 * (Grav must be served at the document root; subdirectory installs
 * break spec-correct WebFinger), and v0.0.5's hostBase rule (must be
 * publicly resolvable https — see HostBaseResolver).
 *
 * The check is intentionally framework-agnostic: it takes the runtime
 * data it needs as constructor arguments. The plugin entry class is
 * responsible for fetching the base URL from Grav and probing for the
 * extension. This keeps PreflightCheck unit-testable without depending
 * on Grav core being autoloadable in the test environment.
 */
final class PreflightCheck
{
    /** @var list<string> */
    private array $errors = [];

    public function __construct(
        private readonly bool $hasPdoSqlite,
        private readonly string $baseUrlPath,
        private readonly string $resolvedHostBase = '',
    ) {
        $this->run();
    }

    public function isHealthy(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    private function run(): void
    {
        $this->checkPdoSqlite();
        $this->checkDocumentRoot();
        $this->checkHostBase();
    }

    private function checkHostBase(): void
    {
        if ($this->resolvedHostBase === '') {
            // Legacy callers (older tests, scaffolding) construct
            // PreflightCheck without passing a hostBase. Treat as
            // "not checked" rather than rejecting — those callers
            // don't drive federation anyway.
            return;
        }
        if (HostBaseResolver::isPublishable($this->resolvedHostBase)) {
            return;
        }
        $this->errors[] = \sprintf(
            "Resolved canonical hostBase '%s' is not usable for federation. "
            . 'Set `federation.canonical_host` to your public https URL '
            . '(e.g. https://www.example.com) in Admin → Plugins → '
            . 'Fediverse Publisher or in user/config/plugins/'
            . 'fediverse-publisher.yaml. Plugin disabled until set.',
            $this->resolvedHostBase
        );
    }

    private function checkPdoSqlite(): void
    {
        if ($this->hasPdoSqlite) {
            return;
        }

        $this->errors[] = 'PHP extension pdo_sqlite is required but not loaded. '
            . 'See README.md for installation guidance. Plugin disabled.';
    }

    private function checkDocumentRoot(): void
    {
        $base = trim($this->baseUrlPath, '/');
        if ($base === '') {
            return;
        }

        $this->errors[] = \sprintf(
            "Detected Grav base path '/%s'. ActivityPub requires Grav to be "
            . 'served at the document root, because WebFinger lives at '
            . '/.well-known/webfinger on the host. Move Grav to the root or '
            . 'configure your webserver to alias /.well-known/webfinger, '
            . '/activitypub/* and /nodeinfo/* to this instance. Plugin disabled.',
            $base
        );
    }
}
