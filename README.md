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

- **Grav** 2.0 RC or later (primary target). 1.7.51+ supported on a
  best-effort basis.
- **PHP 8.1+** with extensions: `pdo_sqlite`, `curl`, `dom`,
  `intl`, `json`, `mbstring`, `openssl`, `simplexml`. `ext-intl`
  is not bundled by default on Debian/Alpine and some FreeBSD ports —
  worth verifying explicitly with `php -m | grep -i intl`.
- **Grav at the document root.** Subdirectory installs are refused
  at activation because WebFinger lives at host-root
  (`/.well-known/webfinger`). If you need Grav under a subdirectory
  AND ActivityPub, alias `/.well-known/webfinger`,
  `/activitypub/*` and `/nodeinfo/*` to the Grav instance in your
  webserver config.
- **HTTPS with a publicly-trusted TLS certificate.** Mastodon and
  friends will not federate without TLS.
- A **per-minute cron** that drives `bin/grav scheduler`. Without
  it the push worker never fires. See "Scheduler" below — Grav
  1.7's CLI does not include a `scheduler-install` shortcut, the
  crontab line has to be added by hand.

## Installation

GPM submission is planned for the v0.1 tag. For now:

```bash
cd user/plugins
git clone https://github.com/Kernel-Error/grav-plugin-fediverse-publisher fediverse-publisher
cd fediverse-publisher
composer install --no-dev
# Set ownership to whatever the webserver user is — common pairings:
#   Debian/Ubuntu (apache/nginx + php-fpm) → www-data:www-data
#   FreeBSD       (nginx + php-fpm)        → www:www
#   Alpine        (nginx + php-fpm)        → nginx:nginx
chown -R www-data:www-data .
```

On FreeBSD the composer package follows the active PHP version:
`pkg install php83-composer` (or `php82-composer` etc.).

Then in the Grav admin: **Plugins → Fediverse Publisher → enable**,
fill in the actor fields, save. On activation the plugin runs a
pre-flight check; if `pdo_sqlite` is missing or Grav is not at the
document root, a clear admin notice explains why the plugin stays
inactive.

## Scheduler

The plugin pushes outbound deliveries on every Grav scheduler tick.
Grav itself doesn't run a daemon — the scheduler is driven by an
external cron entry calling `bin/grav scheduler`. Add this line to
the crontab of the user that runs the webserver (NOT root):

```
* * * * * cd /path/to/grav && /usr/bin/php bin/grav scheduler 1>> /dev/null 2>&1
```

The plugin job is wired up via `onSchedulerInitialized` and runs at
`* * * * *` — once per minute is the minimum cadence the plugin
expects. If the external cron fires less often (e.g. hourly), the
push queue still drains, just at the slower cadence; pushes pile up
between ticks and may bump against the 7-attempt retry cap.

To see what Grav currently has registered, use
`bin/grav scheduler` (no subcommand) — it prints the configured
job list. (Older docs mention `-j status`; that flag is not in
the Grav 1.7 CLI.)

## Webserver — narrow `.well-known/` lockdown

If your vhost forwards all of `/.well-known/` to Grav, no extra
config is needed; WebFinger and NodeInfo just work. Many
hardened setups, though, restrict `.well-known/` to only
`acme-challenge` + `security.txt` and 404 everything else. In
that case Mastodon's discovery probe gets a 404 instead of
WebFinger and `@blog@your-host` looks unresolvable.

For nginx, add two surgical `location =` blocks **before** the
generic `^~ /.well-known/` block (order matters — nginx picks
the most specific match):

```nginx
location = /.well-known/webfinger {
    try_files $uri $uri/ /index.php?$args;
}
location = /.well-known/nodeinfo {
    try_files $uri $uri/ /index.php?$args;
}
location ^~ /.well-known/ {
    # …your existing restrictive policy…
}
```

This forwards exactly the two AP-discovery paths to Grav and
leaves the rest of `.well-known/` under your existing policy.

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
| **`federation.canonical_host`** | (empty) | **Required.** Public https origin URL of the site, no trailing slash, no path, no port — e.g. `https://www.example.com`. Used in actor URL, keyId, inbox/outbox URLs. Must be set explicitly: Grav's CLI scheduler context can't derive this from the request URI, and Fediverse peers reject keyIds that resolve to `localhost`. The plugin's preflight refuses to enable the plugin until this is set to a publishable value. |
| `federation.public_only` | `true` | Only push pages with public visibility. |
| `federation.dev_allow_cidrs` | `[]` | **Dev only.** CIDR allow-list overriding the SSRF reserved-IP block. Keep empty in production. |

## Operator commands

```bash
# Drain the push queue once, synchronously. Useful for dev / smoke.
bin/plugin fediverse-publisher flush-queue

# Clear all Grav caches (mind the syntax — there's no dash):
bin/grav clearcache --all
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

## Troubleshooting

- **Site returns HTTP 500 after the plugin's `composer install`** —
  this used to happen on Grav 1.7 because a transitive Symfony
  dependency pulled `psr/log` v3, which conflicted with Grav core's
  bundled v1. v0.0.2 pins `psr/log` to `^1.1` to avoid this. If you
  still see it: confirm `vendor/psr/log/Psr/Log/LoggerInterface.php`
  in the plugin's vendor has untyped `$message` parameters (v1
  shape) — anything else means an unexpected upgrade. Recovery is
  `mv user/plugins/fediverse-publisher /tmp/`, then
  `bin/grav clearcache --all`, then **restart** PHP-FPM (a reload
  does not clear shared-memory OPcache, which is what's holding the
  poisoned class definitions).
- **`bin/plugin fediverse-publisher flush-queue` returns
  `processed=0`** — most often: the configured `actor.username` is
  empty, the actor's keys aren't on disk yet, or no follower has
  been written. Check `user/data/fediverse-publisher/` for the
  SQLite file + `keys/<username>.private.pem`.
- **An incoming follow stays "pending"** — the follower row landed
  in your DB but the Accept push hasn't gone out yet. Either the
  scheduler isn't running, or the recipient inbox URL we derived
  isn't reachable from the host (e.g. firewalled). The Grav log
  carries one rate-limited entry per remote actor per minute with
  the specific reason.
- **The Grav log shows `push non-2xx — peer rejected delivery`
  with `keyId=http://localhost/...`** — `federation.canonical_host`
  isn't set, or Grav's compiled-config cache hasn't picked up the
  change yet. Set the field via the Admin UI (which invalidates
  both web + CLI config caches) or run `bin/grav clearcache --all`
  AND restart PHP-FPM after a yaml-only edit. See the next
  troubleshooting entry — this is the same cache-invalidation
  pattern.
- **Enabling/disabling the plugin via yaml-edit doesn't take
  effect** — Grav keeps two separate compiled-config caches per
  install (`cache/compiled/config/filelist-config-<host>.php` for
  web requests, `filelist-config-cli.php` for CLI). Each is only
  invalidated on the next access by that specific SAPI. Editing
  yaml + `clearcache --all` flushes them on disk, but under
  PHP-FPM the previous compiled state may still be live in
  OPcache memory until FPM is restarted. The robust path is:
  toggle in the Admin UI (the admin save explicitly invalidates
  the relevant caches) rather than via yaml. If you must use yaml:
  `bin/grav clearcache --all` followed by an FPM restart (not
  reload).
- **Multi-site setups: web endpoints respond 200 but
  `flush-queue` says "plugin not enabled"** — Grav supports
  per-host config overrides under `user/<host>/config/plugins/`.
  When you set the plugin's `Canonical host URL` in the Admin UI
  on a multi-site install, the Admin writes to the host-specific
  override file because it knows the host of the request. The
  Grav CLI doesn't have a host context though, so it reads only
  the global `user/config/plugins/fediverse-publisher.yaml` —
  which still has the default `enabled: false` and an empty
  `canonical_host`. Result: actor / webfinger / outbox all serve
  correctly via the web path, but the scheduler tick and
  `bin/plugin fediverse-publisher flush-queue` silently no-op
  ("plugin not enabled"), so no Accept-pushes ever fire and no
  Create activity ever reaches the queue. Two workarounds:
  (a) keep the plugin config identical in BOTH locations —
  either by saving once via Admin and then copying the
  host-specific file on top of the global one, or by editing
  the global yaml directly; or (b) bootstrap Grav's site
  context manually before invoking the CLI (Grav scheduler can
  be told the host via `--env=<host>` on some setups). The
  plugin doesn't auto-detect multi-site because it has no way
  of knowing what your host topology looks like; flagging it in
  the operator's runbook is the practical answer for now.

## License

[MIT](./LICENSE), copyright 2026 Sebastian van de Meer
(Kernel-Error). See [`NOTICES.md`](./NOTICES.md) for the
third-party dependency licences.
