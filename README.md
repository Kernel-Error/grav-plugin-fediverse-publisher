# Fediverse Publisher (Grav plugin)

Publish a [Grav](https://getgrav.org/) blog to the **Fediverse**
(Mastodon, Pleroma, GoToSocial, Lemmy, Friendica, …) via the
[ActivityPub](https://www.w3.org/TR/activitypub/) protocol.

> **Status:** v0.0.x — first end-to-end pre-release. Federation
> works against GoToSocial; full Mastodon/Pleroma compatibility
> matrix testing is the next milestone before a tagged v0.1.

---

## What works today

- **Discovery** — WebFinger at `/.well-known/webfinger`, NodeInfo at
  `/.well-known/nodeinfo` + `/nodeinfo/2.0`. Remote servers can find
  the local actor by `@username@your-host`.
- **Local actor** — single `Person` actor at
  `/activitypub/actor`, with a self-managed RSA-2048 keypair.
- **Inbound `Follow`** — signed HTTP-Signature verification
  (draft-cavage-12), 9-step pipeline including SSRF-hardened key
  fetch, identity binding, dedup. Accepts the follow, enqueues an
  `Accept` activity for delivery.
- **Inbound `Undo Follow`** — same pipeline, removes the follower.
- **Outbound delivery** — push worker drains a SQLite-backed queue
  on every Grav scheduler tick. Per-item heartbeat, exponential
  backoff with jitter (1m / 5m / 30m / 2h / 12h / 24h, cap 7
  attempts), 410-Gone marks follower stale.
- **Page → broadcast** — when a Grav blog post is saved (within the
  configured path filter), a `Create` activity wraps a `Note` (or
  `Article` if longer than the threshold) and is fanned out to
  every active follower.
- **Content negotiation** — blog post URLs serve HTML on a normal
  `Accept`, and AS 2.0 JSON-LD on `Accept: application/activity+json`.

### Out of scope for v0.1

- Showing inbound likes / boosts / replies on your site.
- DMs / private posts.
- Multi-user actors (one site = one actor).
- Authorized fetch — `AUTHORIZED_FETCH=true` Mastodon instances
  partially federate (we use a heuristic for inbox URL discovery);
  full signed-GET support lands in v0.2.

---

## Requirements

- **Grav** 2.0 RC or later (primary target). 1.7.x supported on a
  best-effort basis.
- **PHP 8.1+** with extensions: `pdo_sqlite`, `curl`, `dom`,
  `intl`, `json`, `mbstring`, `openssl`, `simplexml`.
- **Grav at the document root.** Subdirectory installs are refused
  at activation because WebFinger lives at host-root
  (`/.well-known/webfinger`). If you need Grav under a subdirectory
  AND ActivityPub, alias `/.well-known/webfinger`,
  `/activitypub/*` and `/nodeinfo/*` to the Grav instance in your
  webserver config.
- **HTTPS with a publicly-trusted TLS certificate.** Mastodon and
  friends will not federate without TLS.
- A working **Grav scheduler crontab**
  (`bin/grav scheduler-install`). Without it the push worker
  never fires.

## Installation

GPM submission is planned for the v0.1 tag. For now:

```bash
cd user/plugins
git clone https://github.com/Kernel-Error/grav-plugin-fediverse-publisher fediverse-publisher
cd fediverse-publisher
composer install --no-dev
```

Then in the Grav admin: **Plugins → Fediverse Publisher → enable**,
fill in the actor fields, save. On activation the plugin runs a
pre-flight check; if `pdo_sqlite` is missing or Grav is not at the
document root, a clear admin notice explains why the plugin stays
inactive.

Last step — ensure the Grav scheduler is wired up:

```bash
cd /path/to/grav
bin/grav scheduler-install
```

This adds a single `* * * * *` line to the system crontab that
ticks `bin/grav scheduler` once a minute. The plugin registers
itself on that tick so the outbound push queue is drained
automatically.

## Configuration

Edit in the Grav admin under "Plugins → Fediverse Publisher". The
canonical config file is
`user/config/plugins/fediverse-publisher.yaml`.

| Key | Default | What |
|---|---|---|
| `enabled` | `false` | Master switch. |
| `actor.username` | (empty) | Local part of the federated handle (`@blog@your-host` → `blog`). |
| `actor.name` | (empty) | Display name. |
| `actor.summary` | (empty) | Bio. HTML allowed. |
| `actor.icon_url` | (empty) | Absolute URL to a square avatar. |
| `actor.image_url` | (empty) | Absolute URL to a header image. |
| `blog.path_filter` | `/blog/**` | Which Grav pages become outbox entries (glob). |
| `blog.note_threshold` | `1000` | Characters above which a post is an `Article`, below it's a `Note`. |
| `federation.public_only` | `true` | Only push pages with public visibility. |
| `federation.dev_allow_cidrs` | `[]` | **Dev only.** CIDR allow-list overriding the SSRF reserved-IP block. Keep empty in production. |

## Operator commands

```bash
# Drain the push queue once, synchronously. Useful for dev / smoke.
bin/plugin fediverse-publisher flush-queue
```

Inspecting state from the database directly:

```bash
sqlite3 user/data/fediverse-publisher/fediverse-publisher.sqlite <<SQL
SELECT actor_url, status FROM followers;
SELECT activity_id, recipient_inbox, status, last_http_status FROM push_queue ORDER BY id DESC LIMIT 10;
SELECT activity_id, type, actor_url, datetime(received_at, 'unixepoch') FROM inbox_log ORDER BY received_at DESC LIMIT 10;
SQL
```

Tables that matter:
- `followers` — accepted + pending-accept + stale subscribers.
- `push_queue` — outbound deliveries: pending / processing / done / dead.
- `inbox_log` — every signed inbound activity (id-PK for dedup).
- `actor_key_cache` — remote actor public keys + inbox URLs, 24 h TTL.

## Development

```bash
composer install
composer test          # PHPUnit
composer analyse       # PHPStan
composer lint          # PHP-CS-Fixer dry-run
composer lint:fix      # apply style fixes
```

A local test stack with Grav + GoToSocial behind Caddy/TLS lives in
the parent project repo's `dev/` directory (not part of this plugin
release). See that directory's `README.md` for the runbook.

## License

[MIT](./LICENSE), copyright 2026 Sebastian van de Meer
(Kernel-Error). See [`NOTICES.md`](./NOTICES.md) for the
third-party dependency licences.
