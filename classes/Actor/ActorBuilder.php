<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Actor;

use Grav\Plugin\FediversePublisher\Keys\KeyStore;

/**
 * Assembles the AS 2.0 `Person` Actor JSON-LD for the local actor.
 *
 * v0.1 single-actor URL shape per ADR-004 A-6:
 *   <host>/activitypub/actor
 *   <host>/activitypub/inbox
 *   <host>/activitypub/outbox
 *   <host>/activitypub/followers
 *   <host>/activitypub/following
 *
 * Public-key block shape per ADR-002 §9 (Mastodon-compatible, two-
 * context JSON-LD).
 */
final class ActorBuilder
{
    /**
     * @param array<string, mixed> $config Plugin config (the
     *                                     `plugins.fediverse-publisher`
     *                                     section).
     */
    public function __construct(
        private readonly KeyStore $keys,
        private readonly string $hostBase,                // e.g. "https://blog.local"
        private readonly array $config,
    ) {
    }

    /**
     * @return bool True iff the plugin has enough config to publish an
     *              Actor at all. Used by the dispatcher to refuse 200
     *              answers before the operator filled in the form.
     */
    public function isConfigured(): bool
    {
        return $this->username() !== '';
    }

    /**
     * @return array<string, mixed> JSON-LD-ready associative array.
     */
    public function build(): array
    {
        $username    = $this->username();
        $actorUrl    = $this->actorUrl();
        $keyPair     = $this->keys->loadOrGenerate($username);

        $doc = [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1',
            ],
            'id'                => $actorUrl,
            'type'              => 'Person',
            'preferredUsername' => $username,
            'name'              => $this->str('actor.name', $username),
            'inbox'             => $this->hostBase . '/activitypub/inbox',
            'outbox'            => $this->hostBase . '/activitypub/outbox',
            'followers'         => $this->hostBase . '/activitypub/followers',
            'following'         => $this->hostBase . '/activitypub/following',
            'url'               => $this->hostBase . '/',
            'manuallyApprovesFollowers' => false,
            'publicKey'         => [
                'id'           => $actorUrl . '#main-key',
                'owner'        => $actorUrl,
                'publicKeyPem' => $keyPair->publicPem,
            ],
        ];

        $summary = $this->str('actor.summary', '');
        if ($summary !== '') {
            $doc['summary'] = $summary;
        }

        $iconUrl = $this->str('actor.icon_url', '');
        if ($iconUrl !== '') {
            $doc['icon'] = ['type' => 'Image', 'url' => $iconUrl];
        }

        $imageUrl = $this->str('actor.image_url', '');
        if ($imageUrl !== '') {
            $doc['image'] = ['type' => 'Image', 'url' => $imageUrl];
        }

        return $doc;
    }

    public function username(): string
    {
        return $this->str('actor.username', '');
    }

    public function actorUrl(): string
    {
        return $this->hostBase . '/activitypub/actor';
    }

    private function str(string $dottedKey, string $default): string
    {
        $value = $this->config;
        foreach (\explode('.', $dottedKey) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return \is_string($value) ? \trim($value) : $default;
    }
}
