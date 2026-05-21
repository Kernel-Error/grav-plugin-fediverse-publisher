<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\NodeInfo;

/**
 * Assembles the NodeInfo payload documents.
 *
 * Discovery doc — served at `/.well-known/nodeinfo` — is a tiny pointer
 * JSON listing the schema versions we implement and where to fetch
 * them. The 2.0 doc itself — served at `/nodeinfo/2.0` — carries the
 * actual instance metadata (software, protocols, user count,
 * registration policy, node name + description).
 *
 * Spec: <http://nodeinfo.diaspora.software/protocol.html>
 *
 * Framework-agnostic by design: the caller passes in every dynamic
 * field, so tests don't need Grav.
 */
final class NodeInfoBuilder
{
    public function __construct(
        private readonly string $softwareName,     // e.g. "grav-fediverse-publisher"
        private readonly string $softwareVersion,  // plugin version (semver)
        private readonly string $hostPlatform,     // e.g. "grav"
        private readonly string $hostVersion,      // Grav core version
        private readonly bool   $isConfigured,     // true iff an actor is set up
        private readonly string $nodeName,         // human-readable instance name
        private readonly string $nodeDescription,  // free-form description, may be HTML
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function discovery(string $nodeInfo20Url): array
    {
        return [
            'links' => [
                [
                    'rel'  => 'http://nodeinfo.diaspora.software/ns/schema/2.0',
                    'href' => $nodeInfo20Url,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function nodeInfo20(): array
    {
        return [
            'version'  => '2.0',
            'software' => [
                'name'    => $this->softwareName,
                'version' => $this->softwareVersion,
            ],
            'protocols' => ['activitypub'],
            'services'  => [
                'inbound'  => [],
                'outbound' => [],
            ],
            'openRegistrations' => false,
            'usage' => [
                'users' => [
                    // Single-actor MVP. 0 until the operator has filled
                    // the username field, then 1 forever.
                    'total' => $this->isConfigured ? 1 : 0,
                ],
                'localPosts' => 0,        // outbox count — populated by ADR-004 once Outbox lands
            ],
            'metadata' => [
                'nodeName'        => $this->nodeName,
                'nodeDescription' => $this->nodeDescription,
                'host'            => [
                    'platform' => $this->hostPlatform,
                    'version'  => $this->hostVersion,
                ],
            ],
        ];
    }
}
