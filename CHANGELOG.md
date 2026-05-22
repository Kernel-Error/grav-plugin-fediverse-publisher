# Changelog

All notable changes to this project are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed (v0.0.7 — broadcast pipeline + 4.5-compat + hashtags)

v0.0.6 production deploy on beratung-rheinbach.de got the follow
handshake working end-to-end against real Mastodon — first time
the project closed that loop in five iterations. But the real
broadcast test surfaced a showstopper, plus two follow-on issues
the operator caught while spot-checking federated rendering.

- **`onAdminAfterSave` doesn't fire under Grav 1.10+ Admin.**
  The Admin since 1.10 (default flex-objects plugin) routes page
  saves through the Flex pipeline, which fires `onFlexAfterSave`
  instead — see
  `user/plugins/flex-objects/classes/Admin/AdminController.php:968`.
  The plugin subscribed only to the classic event, so the entire
  outbound broadcast for new posts was silently dead on any
  modern Grav install. Working follow-handshake and outbox crawl
  masked it until somebody actually published a real post.
  Fixed by subscribing to both events; the
  `(activity_id, recipient_inbox)` UNIQUE constraint makes
  double-fire idempotent. Plus an unconditional diagnostic log
  at handler entry (object class + best-effort route + event
  keys) so the next regression of this class is visible in one
  log line instead of taking 30 min to track down. Plus a small
  `looksLikePage()` duck-type replacing the strict
  `method_exists()` chain — classic Page and Flex PageObject
  both expose the same surface but don't share an instanceof
  parent we could match cheaply. New `EventWiringTest`
  source-grep guards against the regression class.

- **Mastodon 4.5.x doubled the post count.** A second follow
  from bonn.social (Mastodon 4.5.9 LTS) showed the actor profile
  with 10 posts instead of 5. Root cause: the `Create` activity
  `id` was `…/<slug>#create-<unix>` (with fragment), the inner
  `object.id` was `…/<slug>` (no fragment). Mastodon 4.5 indexes
  both as separate Status rows; 4.6+ strips the fragment before
  dedup. v0.0.7 emits the activity id as a fragment-less
  sibling path (`…/<slug>/activity/create-<unix>`); idempotency
  preserved since same page + same publish time still yields
  the same id. Three new tests pin the distinct-from-object,
  fragment-less, and trailing-slash-safe properties.

- **AS 2.0 `updated` must not predate `published`** (regression
  guard inherited from v0.0.6). Already shipped; kept in the
  changelog because the new broadcast pipeline now exercises
  it on every page save.

- **`federation.public_only` was a dead config knob.** Declared
  in blueprints.yaml since v0.0.1, never read by any code.
  v0.0.7 still doesn't implement it (the implementation is an
  open design question — Grav's "access" frontmatter is the
  closest analogue but the semantics differ per theme), so the
  field is removed from blueprints + default yaml to avoid the
  "I ticked it, why does it not work" trap. Re-add when a
  concrete semantic is decided.

### Added (v0.0.7)

- **Hashtag federation.** Grav's `taxonomy.tag` frontmatter is
  now propagated as AS 2.0 `Hashtag` objects on the Article /
  Note. v0.0.6 dropped the taxonomy entirely; the operator's
  estimate "this costs roughly 80% of the broadcast value" was
  not exaggeration — hashtag indexing on Mastodon's per-instance
  `#tag` timeline is the primary amplification path for posts
  from an actor with zero followers on day one. Implementation
  mirrors the v0.0.6 attachment pattern: PageRecord gains
  `tags: list<string>`, GravPageSource extracts defensively,
  ActivityTransformer emits Hashtag with `#`-prefixed `name`
  (required for Mastodon indexing) and URL-encoded `href`
  pointing at Grav's per-tag landing page. Plugin entry derives
  the tag-base URL from `blog.path_filter` automatically — no
  new config knob. Tags whose name contains whitespace are
  skipped (Mastodon's parser would mis-index). Six new tests.

- **`bin/plugin fediverse-publisher broadcast:post <route>`** —
  manual recovery CLI for posts that were saved under v0.0.6
  and therefore never reached the queue. Resolves the route
  through the same `GravPageSource` the broadcaster uses, calls
  `OutboundBroadcaster::broadcast()` directly. Idempotent via
  the queue UNIQUE constraint, error paths are operator-friendly.

- **`bin/plugin fediverse-publisher push:purge-dead`** —
  operator housekeeping. Drops terminal `dead` rows from
  push_queue. Optional `--older-than=N` flag keeps recent
  failures visible for debugging while purging the ancient
  stragglers. v0.0.6 production carried two dead rows from the
  v0.0.3/v0.0.4 broken-localhost-keyId attempts; this is the
  cron-friendly way to keep the table tidy across deploy
  iterations. Four new tests on the storage method.

### Documentation (v0.0.7)

- README troubleshooting gains a "Multi-site setups" entry that
  reproduces the exact symptom from the v0.0.6 deploy: Admin UI
  writes the plugin config to `user/<host>/config/plugins/`
  while the CLI reads only `user/config/plugins/`, so web
  endpoints come up green but `flush-queue` silently bails
  with "plugin not enabled". Two workarounds documented.

### Fixed (v0.0.6 — local E2E smoke catch)

Found during the v0.0.5 pre-production end-to-end run against
GoToSocial, NOT in production for once. The full Follow → Accept →
post-create → broadcast cycle ran clean over the wire (200s
everywhere), but the test post never appeared in the GTS timeline
even though the inbound POST was accepted with 202. GTS's worker log
told us why:

```
status https://blog.local/blog/v005-e2e
  'updated' predates 'published'
```

`ActivityTransformer` emitted the AS 2.0 object with `published`
set from Grav's `$page->date()` (the operator-set frontmatter date)
and `updated` from `$page->modified()` (the file mtime). For posts
where the operator back-dates or future-dates the frontmatter,
mtime sits earlier than the published date, so the activity went
out with `updated < published` — well-formed for the dumb peers,
silently rejected by the strict ones. GTS, Mastodon, and Pleroma
all enforce the spec rule here.

- **`ActivityTransformer::transformObject()` clamps `updated`** to
  be at least `published`. The normal case (edited post, mtime
  later than published) is unaffected; back-dated and future-dated
  posts now produce a coherent activity. Two new unit tests pin
  both branches.

### Fixed (v0.0.5 — fourth production-deploy bug-fix)

The v0.0.4 diagnostics-first push paid off: the new response-body
logging surfaced Mastodon's actual rejection reason for the
outbound 401 within seconds — `"Requests to private network
addresses are disallowed (tried to query Mastodon::PrivateNetwork
AddressError on http://localhost/activitypub/actor#main-key)"`.
That's two separate bugs in one diagnostic line, plus a third that
turned up while reproducing the first one locally.

- **psr/log v1 ↔ v3 autoload-prepend conflict.** Under Grav 2.0 RC
  the plugin's `vendor/autoload.php` registered with
  `prepend=true`, putting the plugin's pinned psr/log v1.x in
  front of Grav's bundled v3 for any class both define. The
  shutdown handler's Whoops integration triggered
  `Psr\Log\AbstractLogger` autoload, our v1 implementation got
  served against the v3 `LoggerInterface` already in memory, and
  PHP fatal'd on the signature mismatch. Symptoms varied by SAPI
  — web 500 site-wide on Grav 2.0, CLI scheduler tick fatal on
  both 1.7 and 2.0. Fix: `autoload()` now re-registers the loader
  with `prepend=false` so the host Grav vendor wins for shared
  classes. Belt-and-braces also scrubs every `new NullLogger()`
  from production code: `RequestSigner`'s default became
  `?LoggerInterface = null` with nullsafe call sites, and
  `FlushQueueCommand` resolves Grav's real logger instead of a
  NullLogger sentinel. Documented as outstanding architectural
  debt: vendor-prefixing (php-scoper / Strauss) would make this
  class of conflict structurally impossible, not just observably
  rare.
- **Outbound `keyId` resolved to `http://localhost` under CLI.**
  `resolveHostBase()` derived its return value from
  `$grav['uri']->rootUrl(true)` with a `$_SERVER['HTTP_HOST']`
  fallback. In CLI context (cron-driven scheduler tick, manual
  `bin/plugin fediverse-publisher flush-queue`) both are
  empty/localhost, so every signed `Accept` and `Create` went out
  with a `keyId` pointing at a private-network address. Mastodon
  refused with 401, GoToSocial with 500 (same root cause, peer's
  SSRF protection). Fix: introduce a mandatory
  `federation.canonical_host` config field (admin form + yaml),
  defaulting to empty so the operator must set it explicitly.
  The new `HostBaseResolver` class is pure-PHP — every input
  passed in as an argument — so the matrix of (web, CLI,
  cron-without-Grav-uri) is unit-testable without booting Grav.
  `PreflightCheck` extended: refuses to enable the plugin when
  the resolved hostBase isn't publishable (loopback, RFC 1918,
  private IPv6, raw Unicode host, http-only, port set, or path
  segment all rejected with a clear error message).
- **`$clock` named-parameter drift in `FlushQueueCommand`.** The
  release-polish round dropped the `Clock` constructor parameter
  from `Dispatcher` but `FlushQueueCommand` still passed
  `clock: $clock`, plus the obsolete `allowedReservedCidrs:`.
  Fatal on every CLI tick that had work to drain. Fixed by
  bringing the FlushQueueCommand constructor call into sync with
  the current `Dispatcher::__construct` signature.

### Changed (v0.0.5)

- **`federation.canonical_host` is now a required config field.**
  Admin blueprint marks it `required: true` with a regex
  validator (`^https://[a-z0-9.\-]+/?$`). Operators must set the
  public https origin URL of the site — origin only, no path,
  no port. v0.0.4 yaml files need this field added before the
  plugin will run; the preflight error message points at exactly
  this fix.
- **Local dev stack gains a second Grav container.** Same
  bind-mounted plugin source, but `fpub-grav-17` runs Grav 1.7.52
  on PHP 8.3 (matching the `beratung-rheinbach.de` production
  jail exactly) alongside the existing Grav 2.0 RC container.
  v0.0.5's psr/log fix is verified against both Gravs locally;
  pre-v0.0.5 we shipped Grav-2.0-only smokes and the entire
  Grav-1.7-vs-Grav-2.0 conflict class ended up surfacing in
  production. See `dev/README.md` for the dual-stack layout.

### Added (v0.0.5)

- `classes/Config/HostBaseResolver.php` — pure PHP class that
  produces a deterministic canonical hostBase. Tested with a
  full matrix of inputs: configured canonical wins, falls back
  through Grav's rootUrl then `$_SERVER`, ends at
  `http://localhost` (caught by preflight, not by the resolver).
  `isPublishable()` is the gate: rejects http, loopback, IPv4
  privates, IPv6 link-local / ULA / loopback, raw Unicode hosts
  (operator must publish A-labels), port literals, path
  segments, query strings, fragments. 22 new unit tests on this
  one class alone.
- Tightened admin validation on `federation.canonical_host` to
  match the resolver's contract: regex is now origin-only,
  required, with the placeholder example operator-friendly.
- The plugin's `autoload()` method now documents the
  prepend-vs-append decision inline so the next maintainer
  doesn't reflexively "fix" it back to prepend on the theory
  that the plugin should win class-loading.

### Fixed (v0.0.4 — third production-deploy bug-fix)

v0.0.3 closed the SQL crash and got real Mastodon Follow activities
accepted at the inbox layer, but two things from the v0.0.3
changelog turned out to not actually ship, plus the new listing-page
filter overshot, plus Mastodon rejected every outbound `Accept` with
HTTP 401. End-to-end federation was still not working after v0.0.3.

- **Followers / following routes wired into `buildRouter()`.** The
  controllers and their unit tests landed in v0.0.3 but the actual
  `$router->get(...)` registrations never made it into
  `fediverse-publisher.php`. Mastodon hit both URLs during profile
  resolution and got Grav's HTML 404, which rendered as
  "0 followers" regardless of real state. v0.0.4 also adds a
  source-grep `RouterWiringTest` that asserts each spec-required
  route is registered, so this class of "unit tests green,
  integration forgotten" miss can't happen again silently.
- **Listing-page detection by structure, not template name.**
  v0.0.3 keyed the listing-vs-post decision on the Twig template
  name (`blog`/`archive`/`listing`/`collection`). Real Grav blogs
  conventionally name their post files `blog.md` inside per-post
  directories — same template name as the index — so the filter
  false-positived every post. v0.0.4 detects a listing as "the
  page has children" (`$page->children()->count() > 0`). Grav blog
  trees are `09.blog/blog.md` (listing, has children) →
  `09.blog/<post>/<file>.md` (post, no children); the structural
  signal is robust against copy-pasted frontmatter artefacts.
- **`attachment` falls back to the page's media folder.** v0.0.3
  scanned only `<img src=…>` in the body HTML. Posts that keep
  the hero image next to the markdown without an inline `<img>`
  ended up with no attachment, so Mastodon couldn't render a
  card thumbnail. `PageRecord` now carries a `mediaImageUrls`
  field populated by `GravPageSource` from `$page->media()`;
  `ActivityTransformer::buildAttachments()` merges body-HTML
  references with media-folder images, deduplicating on URL.
- **Outbound HTTP signature: defensive Host force-set + failure
  logging.** A live Follow on the production site was met with
  HTTP 401 from Mastodon on every retry. The signer now
  unconditionally overwrites any existing Host header with the
  value derived from the request URI (defensive — PSR-7
  implementations occasionally carry an auto-derived Host that
  diverges from URI), and emits a debug log entry containing the
  exact signing string used. The dispatcher now logs the response
  body, signature header, date, digest and host header on every
  non-2xx outcome, so the next test cycle has a real Mastodon
  rejection reason to work from instead of a status code alone.
- **`RequestSigner` accepts an optional PSR-3 logger.** Defaults
  to a `NullLogger` so existing tests don't have to thread one
  through. The plugin entry's `buildDispatcher()` now passes
  Grav's logger so the diagnostics from the previous bullet
  actually land in `grav.log`.

### Fixed (v0.0.3 — second production-deploy bug-fix)

The v0.0.2 deploy got us past the boot crash from v0.0.1 but
surfaced a fresh SQL bug under PHP 8.3 / SQLite: every push-queue
write blew up with `no such column: "pending"`. Root cause:
double-quoted string literals in the SQL. SQLite parses
`"pending"` as an identifier (column name) and only falls back to
a string literal when the identifier doesn't resolve — and that
fallback is disabled in the PHP 8.3 SQLite build. The fix
touches every SQL statement in `OutboundQueue` and the two
`FollowerStore` writes that had the same shape.

- **All SQL string literals switched to single quotes.** PHP outer
  string is now double-quoted (so `\n` inside the SQL stays
  readable), inner SQL literals are single-quoted (`'pending'`,
  `'processing'`, `'done'`, `'dead'`, `'stale'`). `OutboundQueue`
  fully rewritten with a block comment explaining the footgun so
  future-you doesn't reintroduce it. Two spots fixed in
  `FollowerStore::markAccepted()` and `FollowerStore::listActive()`.

### Added (v0.0.3 — endpoints and richer Article)

- **`GET /activitypub/followers` endpoint.** v0.0.2 declared the
  endpoint in the actor JSON-LD but never registered a handler;
  Mastodon hit the URL, got Grav's HTML 404 page, and rendered
  the profile as "0 followers" even when followers existed
  locally. The new `FollowersCollectionController` mirrors the
  `OutboxController` shape: bare `OrderedCollection` summary by
  default, paginated `OrderedCollectionPage` (20 per page) under
  `?page=true&p=N`. `FollowerStore` gained `listForCollection()`
  + `countForCollection()` to back it.
- **`GET /activitypub/following` endpoint.** Same story —
  declared but unimplemented. The new
  `FollowingCollectionController` always responds with an empty
  collection (v0.1 is broadcast-only; the local actor publishes,
  never follows). The real implementation lands with the
  multi-account work in v0.3+.
- **Listing-page filter in `GravPageSource`.** v0.0.2 federated
  the `/blog` index page itself as a "post" with empty body.
  The new `isListingTemplate()` rejects pages whose Twig template
  name is `blog`/`archive`/`listing`/`collection` — Grav-skeleton
  convention for container pages. `hasNonEmptyContent()` is a
  second-line defence: any page whose rendered HTML strips down
  to whitespace is also dropped. Either check is sufficient to
  flag a listing.
- **`ActivityTransformer` builds `summary` + `attachment`.**
  Mastodon's article rendering reaches for `summary` (used as
  the post excerpt / preview-card description) and `attachment`
  (used as the hero image). v0.0.2 emitted neither, so Mastodon
  fell back to OpenGraph parsing on the public URL — which
  usually doesn't pick a sensible excerpt or thumbnail.
  `buildSummary()` extracts the first `<p>…</p>` (or the whole
  body if there's no `<p>`), strips HTML, decodes entities,
  collapses whitespace, caps at 200 chars. `buildAttachments()`
  walks `<img src=…>` references; absolute and root-relative
  URLs become AS 2.0 `Document` objects with `mediaType`
  inferred from the file extension and `name` set from `alt`
  when present. Relative paths get dropped (peers can't fetch
  `./images/foo.jpg`).

### Fixed (v0.0.2 — first production-deploy bug-fix)

The first attempt at deploying v0.0.1 on a Grav 1.7 production site
(beratung-rheinbach.de, PHP 8.3.30 on FreeBSD) took the site down
with HTTP 500 site-wide as soon as the plugin's `composer install`
ran — without the plugin even being enabled. Root cause was a
transitive-dependency conflict; recovery required moving the
plugin directory out of the way and restarting PHP-FPM (a `reload`
doesn't clear OPcache).

- **`psr/log` pinned to `^1.1`** in `composer.json`. The previous
  `symfony/cache 7.4` transitive dragged in `psr/log` v3, whose
  typed `emergency(string|Stringable $message, ...)` signature
  conflicts with Grav 1.7's bundled v1 (untyped `$message`). PHP
  hard-fails on the incompatible declaration at autoload time and
  the entire site goes 500 — including any request to a disabled
  plugin, because Grav still calls `autoload()` on disabled
  plugins. v0.0.2 pins to v1.1.x explicitly so the dep never
  bumps above the version Grav 1.7 ships.
- **Defensive boot.** `autoload()`, `runPreflight()`,
  `onPagesInitialized()`, and `onPageInitialized()` now catch
  `\Throwable` and degrade to a no-op + error_log entry instead of
  letting the host site hit 500. The plugin can fail closed
  without taking the surrounding Grav installation with it.
- **`PreflightCheck` loaded via explicit `require_once`** before
  the Composer autoloader runs, not via PSR-4. So even if vendor/
  is gone or broken, the preflight class itself remains available
  and can still emit a clear admin notice.

### Changed (v0.0.2 — README accuracy from production feedback)

- Pre-flight extension list now mentions `ext-intl` explicitly
  (not always present on Debian/Alpine/FreeBSD without an extra
  package).
- Scheduler section rewritten — Grav 1.7 has no
  `bin/grav scheduler-install` or `scheduler-status` shortcut.
  The README now documents the manual crontab line.
- `bin/grav clearcache --all` (no dash inside the command name)
  noted in the operator section.
- FreeBSD composer package name (`php83-composer` etc.) noted in
  the install section.
- Common `chown` placeholders filled in for Debian / FreeBSD /
  Alpine instead of bare colons.
- New Troubleshooting section captures the psr/log recovery
  recipe, "flush-queue returns processed=0" diagnosis, "follow
  stays pending" diagnosis.

### Added
- Initial plugin scaffold: `composer.json` with the dependency set
  ratified by ADR-002 (`landrok/activitypub`, `phpseclib/phpseclib`,
  `guzzlehttp/guzzle`).
- `blueprints.yaml` and default `fediverse-publisher.yaml` exposing
  the v0.1 admin form fields (actor metadata, blog path filter,
  federation toggle).
- `PreflightCheck` class running on `onPluginsInitialized` that refuses
  activation when `pdo_sqlite` is missing (ADR-001 A-2) or Grav is
  served from a non-root URL base (ADR-004 A-4).
- PHPUnit, PHPStan and PHP-CS-Fixer configs.

### Fixed
- Plugin's `autoload()` must be `public` (the Grav 2.0 core calls it
  via `$plugin->autoload()` and feeds the result into
  `setAutoloader()`). Earlier scaffold made it private, which
  silently broke plugin bootstrap with hundreds of `Call to private
  method ... from scope Grav\Common\Plugins` log entries. Method is
  now public and follows the `grav-plugin-form` canonical shape
  (returns `?ClassLoader`, no-ops cleanly if `vendor/` is missing).

### Added (Block 1 — actor discovery)
- `Keys\KeyStore` — RSA-2048 keypair generation via `phpseclib3`,
  atomic-write PEM persistence (tmp + rename), 0600/0644 modes.
  Per-actor file naming under `user/data/fediverse-publisher/keys/`.
  Refuses to overwrite existing keys (rotation is an explicit v1.x
  feature, not an accident).
- `Keys\KeyPair` — immutable value object carrying public/private
  PEM strings.
- `Actor\ActorBuilder` — assembles the AS 2.0 `Person` JSON-LD with
  the two-context shape (`activitystreams` + `security/v1`), the
  Mastodon-style `publicKey` block, and the v0.1 single-actor URL
  scheme. Omits `summary`/`icon`/`image` when not configured.
- `Http\Router` — tiny exact-path + method dispatcher. Returns 405
  with `Allow` header on method mismatch, answers HEAD off GET with
  empty body + preserved Content-Length.
- `Http\WebFingerController` — RFC 7033 JRD for the configured
  actor. Validates `resource=acct:<user>@<host>`, case-insensitive
  host match, 400 on malformed input, 404 on unknown account.
- `Http\ActorController` — serves `/activitypub/actor` as
  `application/activity+json` with `Cache-Control: no-store`.
- Plugin entry now wires up the dispatcher: after preflight passes
  and only for paths under `/.well-known/{webfinger,nodeinfo}`,
  `/nodeinfo/` and `/activitypub/`, the request is routed and
  `$grav->close($response)` terminates Grav before the page system
  fires.
- Composer deps: `nyholm/psr7` ^1.8 + `nyholm/psr7-server` ^1.1 for
  PSR-7 implementations (Grav core's `close()` accepts PSR-7).
- 40 unit tests, 105 assertions across `Keys`, `Actor`, `Http`
  (Router, WebFingerController, ActorController, plus the existing
  `PreflightCheck` suite). All green on host PHP 8.3.

### Added (Block 2c — Inbox + signature verifier)
- `Signature\Canonicalizer` — owner-URL form for identity-binding
  (`ownerUrl()`, fragment stripped) AND key-selection form
  (`keyId()`, fragment preserved). Both apply https-only + IDNA
  UTS46 + host lowercase + default-port strip + trailing-slash trim.
- `Signature\MediaType` — strict `application/activity+json` /
  AS-profiled `application/ld+json` matcher. Rejects
  `text/plain; x=application/activity+json` style parameter
  injection (R3-6).
- `Signature\Clock` + `SystemClock` + `FrozenClock` — time
  abstraction so date-skew tests can pin "now" deterministically.
- `Signature\DateChecker` — Mastodon-aligned freshness window
  (`now − 12 h ≤ Date ≤ now + 1 h`, R2-4).
- `Signature\DigestChecker` — verifies `Digest: SHA-256=<base64>`
  with `hash_equals`. Empty-body case covered (R3-2 footgun
  reminder).
- `Signature\SignatureHeader` — parses `keyId`/`algorithm`/`headers`/
  `signature` parameters, lowercases algorithm + header names, rejects
  empty fields.
- `Signature\CryptoVerifier` — phpseclib3 RSA-PKCS1-SHA256 verify.
  Explicitly **does not** route through landrok (R3-1 closes the
  no-network gap; landrok 0.8.1's `HttpSignature` does its own
  HTTP fetch).
- `Signature\KeyFetcher` — SSRF-hardened HTTPS GET of the remote
  actor document: https-only + non-443 port refused (R3-5) +
  IDNA + IP allow-list (rejects RFC 1918 / link-local / ULA /
  IPv4-mapped reserved per R2-3) + pinned IP via `CURLOPT_RESOLVE` +
  no redirects + 64 KiB body cap + strict media-type parse (R3-6) +
  PEM-validates-as-RSA-≥-2048 (R3-7) + fragment-aware
  `publicKey.id ↔ keyId` match (R3-3).
- `Signature\KeyCache` + `CacheEntry` — SQLite actor-key store with
  positive (24 h) and negative (15 min, R2-1) cache windows.
  Failure does NOT wipe a previously-cached PEM (`putFailure()`
  preserves columns).
- `Signature\KeyProvider` — orchestrates cache + fetcher with the
  negative-cache fast-fail (R2-1). Only component on the verifier
  path that may issue a remote HTTP call.
- `Signature\RateLimitedLogger` — one entry per canonical keyId per
  minute. Bucket keyed by `Canonicalizer::ownerUrl()` so cosmetic
  variation can't bypass it (R2-2).
- `Signature\VerificationResult` — value object (200 / 202 / 400 /
  401 + reason + activity + verified key).
- `Signature\Verifier` — 9-step pipeline: Signature parse →
  algorithm name → required signed headers → date freshness →
  digest → **structural prechecks** (R3-2 — refuses fetch on bodies
  without a usable `id`/`type`/`actor`) → key resolve →
  `CryptoVerifier` → identity binding → `InboxLog` dedup.
- `Storage\Database` — PDO/SQLite connector with WAL + sane
  pragmas, runs all table migrations idempotently on first connect.
- `Storage\InboxLog` — `inbox_log` with `INSERT OR IGNORE` for
  idempotent inbox per spec.
- `Storage\FollowerStore` — `followers` table with `pending_accept`
  / `accepted` / `stale` status transitions, ADR-003-R2-2-shaped
  stale counters reserved.
- `Push\OutboundQueue` — schema-only stub for ADR-003-R2-1
  `push_queue`. Provides `enqueue()` so `FollowHandler` can hand
  off `Accept` activities; the worker that drains it is Block 2d.
- `Inbox\Activities\FollowHandler` — validates that `Follow.object`
  resolves to OUR actor URL, upserts the follower row in
  `pending_accept` state, enqueues the matching `Accept` for
  delivery, returns 202 fast.
- `Inbox\Activities\UndoFollowHandler` — strict shape match
  (`object.type == 'Follow'`, inner actor matches outer signer,
  inner target is us), removes the follower row.
- `Inbox\InboxController` — strict Content-Type check (415) +
  **bounded body reader** (R3-4, 1 MiB + 1 byte cap → 413) + JSON
  parse (400) + `Verifier::verify()` (401 / 202) + dispatch to
  Follow / Undo handlers.
- Plugin entry: registers `POST /activitypub/inbox`, wires the
  full verifier stack (Database, KeyCache, KeyFetcher with a
  `dns_get_record`-based resolver, KeyProvider,
  RateLimitedLogger with a NullLogger placeholder, FollowerStore,
  OutboundQueue, Follow + Undo handlers, InboxController).
- 55 new unit tests across `Signature/`, `Storage/`, `Inbox/`.
  Full suite now **130 tests / 288 assertions**, all green on
  host PHP 8.3.
- Smoke tests against the live container confirm: 415 on bad
  Content-Type, 401 on missing signature, 405 + `Allow: POST` on
  GET, SQLite database materialised under
  `user/data/fediverse-publisher/fediverse-publisher.sqlite`.

### Added (release polish + repo bootstrap)
- `cli/FlushQueueCommand.php` — `bin/plugin fediverse-publisher
  flush-queue` synchronously drains the outbound push queue. Useful
  for dev + smoke-testing without waiting for the scheduler tick.
  Builds the Dispatcher independently of the plugin's runtime
  wiring (the `onPluginsInitialized` hook doesn't fire the same
  way in CLI mode).
- `.github/workflows/ci.yml` — CI matrix on PHP 8.1 / 8.2 / 8.3
  running PHPUnit, PHPStan, and PHP-CS-Fixer. Composer cache by
  PHP version + composer.json hash.
- `.github/dependabot.yml` — weekly composer scan with dev-deps
  grouped, monthly actions scan.
- `.gitattributes` with `export-ignore` for dev-only files (tests,
  PHPUnit / PHPStan / PHP-CS-Fixer configs, .github tree) so
  `composer create-project` and `git archive` produce clean
  releases.
- `SECURITY.md` with a private-disclosure email.
- `blueprints.yaml`: added a top-level `compatibility: { grav:
  ['1.7', '2.0'] }` block so the Grav 2.0 RC admin shows both
  badges in the plugin overview. The `dependencies` clause alone
  only lights up the lower-bound version.

### Changed (release polish)
- `Push\Dispatcher`: dropped unused `Clock` and
  `allowedReservedCidrs` constructor parameters. Clock was
  threaded through speculatively; the SSRF allow-list is only
  relevant for the verifier-side `KeyFetcher`, never for outbound
  push (we only POST to inboxes that came from a previously-
  verified actor doc).
- `Signature\KeyFetcher`: `User-Agent` is now config-derived
  (`grav-plugin-fediverse-publisher/<version> (+<site-url>/)`)
  instead of the hardcoded `blog.local` placeholder.
- `NOTICES.md`: now reflects the actual state — none of
  `wordpress-activitypub`'s code is ported into this plugin.
  Signer + Verifier are fresh implementations against
  draft-cavage-12; WP-AP was studied as an architecture reference,
  not copied.
- `README.md`: rewritten from scaffold-stage description ("v0.0.x
  scaffold, federation in subsequent commits") to v0.1 status
  ("end-to-end verified against GoToSocial"). Adds an operator
  section with the SQLite inspection queries and the new CLI
  command.

### Added (Block 2d — push worker + page broadcast)
- `Signature\Signer` — phpseclib3-direct RSA-PKCS1-SHA256 signer.
  Symmetric to `CryptoVerifier`, no landrok involvement.
- `Signature\RequestSigner` — builds the Cavage-style signing string
  from a PSR-7 request, adds `Date`/`Digest`/`Host`/`Content-Type`,
  attaches the final `Signature:` header. Signed-headers set:
  `(request-target) host date digest content-type`.
- `Push\RetryPolicy` — 1m / 5m / 30m / 2h / 12h / 24h schedule with
  full jitter (0.5×–1.5× nominal), cap 7 attempts (ADR-003).
- `Push\DeliveryOutcome` + `Push\FailureClassifier` — maps HTTP
  status / network error to Success / GoneForever / Transient /
  Permanent / Exhausted per ADR-003 R2-2.
- `Push\QueueRecord` — value object wrapping a `push_queue` row.
- `Push\OutboundQueue` (full version) — `reclaimStuck()`,
  `claimBatch()` (BEGIN IMMEDIATE + 2-step claim), `heartbeat()`,
  `markDone()` / `markDead()` / `reschedule()`. Heartbeat semantics
  per ADR-003 R2-1: workers refresh `claimed_at` before each
  delivery, reclaim threshold drops to 2 minutes (longer than
  a single 30 s delivery, shorter than a realistic crash window).
- `Push\Dispatcher` — drains the queue. One transaction-bound
  claim per batch (up to 20 rows or 5 s walltime), per-row
  heartbeat, classify outcome, finalise (markDone / markDead /
  reschedule with backoff). On 2xx for a `pending_accept` follower,
  flips them to `accepted`. On 410, removes the follower row.
- `Outbox\OutboxBroadcaster` — page-save fan-out. Builds the
  `Create` activity once, enqueues one row per active follower.
  Idempotent on (activity_id, recipient_inbox) so double-saves
  don't fan-out twice.
- Plugin entry: subscribes to `onSchedulerInitialized` (registers
  the dispatcher as a 1-minute Grav scheduler job),
  `onAdminAfterSave` (page-save broadcast hook). `runPushDispatcher()`
  is also callable from a CLI command for dev flush. The KeyFetcher
  now takes a configurable `User-Agent` (previously had `blog.local`
  hardcoded — fixed before shipping).
- **End-to-end v0.1 federation verified**:
  1. dev@gts.local follows @blog@blog.local from the GTS API,
  2. Verifier accepts the signed Follow,
  3. FollowHandler enqueues an `Accept` activity,
  4. Dispatcher signs + POSTs it to `gts.local/users/dev/inbox`,
  5. GTS verifies our signature, returns 200,
  6. The follower row flips to `accepted`,
  7. A second blog post is created in Grav,
  8. OutboxBroadcaster enqueues a `Create` for the one follower,
  9. Dispatcher signs + POSTs it to GTS,
  10. The note appears in dev's home timeline on GTS.
  GTS-side `followers_count: 1`, `statuses_count: 2`. **v0.1
  broadcast-only federation works.**
- 38 new unit tests across `Signature/Signer`,
  `Signature/RequestSigner`, `Push/RetryPolicy`,
  `Push/FailureClassifier`, `Push/OutboundQueue` (claim race +
  heartbeat + reschedule + reclaim), `Outbox/OutboxBroadcaster`.
  Full suite **170 tests / 355 assertions**, all green.

### Added (Block 2c — Follow-up: real federation against GoToSocial)
- `Canonicalizer::authority()` — extracts `https://host[:port]` for
  the identity-binding host comparison. The R2-2 rule
  `ownerUrl(keyId) === owner === actor` only holds for
  fragment-based keyIds (Mastodon-style). GoToSocial / Pleroma use
  path-based keyIds (`/users/x/main-key`), so the right invariant
  is `authority(keyId) === authority(owner) === authority(actor)`
  plus `owner === actor`. Both checks now in `Verifier` step 8.
- `KeyFetcher` learnt a third actor-doc shape — **partial actor**
  (GoToSocial 0.21 authorized-fetch default): the keyId URL returns
  the actor's `id` + `publicKey` block but strips inbox/outbox/etc.
  For v0.1 we synthesise the inbox URL via the de-facto convention
  `<owner>/inbox`; v0.2 replaces this with a signed GET of the
  owner URL per ADR-002 §6.
- `federation.dev_allow_cidrs` config knob: optional CIDR allow-list
  that punches reserved-IP ranges through the SSRF block. For local
  dev where peers sit on `10.0.0.0/8` (podman default). Production
  stays empty.
- `KeyCache` column semantics tightened: `owner_url` column is the
  cache key (canonical keyId), `key_id` column is the resolved
  owner URL. Schema unchanged; reading code updated.
- `Verifier::buildSigningString()` now picks the first occurrence of
  each header. Caddy + `php_fastcgi` sometimes delivers the `Host`
  header twice; PSR-7's `getHeaderLine()` joins with `, `, which
  doesn't match what the sender signed.
- Plugin entry: `RateLimitedLogger` now wraps Grav's own logger
  (`$grav['log']`) so verification rejections appear in
  `user/logs/grav.log`. Falls back to a Monolog writing to
  `fediverse-publisher.log` if Grav's logger isn't available.
- **End-to-end Follow from GoToSocial succeeds**: from inside
  `https://gts.local`, `dev@gts.local` follows `@blog@blog.local`.
  Our Verifier accepts the signed Follow (all 9 steps clean), the
  FollowHandler writes `dev@gts.local` to the `followers` table
  with `status='pending_accept'`, and the matching `Accept`
  activity is enqueued on `push_queue` for the (still-to-build)
  worker. Inbox dedup populated, key cache warm. **Block 2d brings
  the loop closure** — the Accept actually being delivered.

### Added (Block 2b — Outbox + content negotiation)
- `Outbox\PageRecord` — framework-agnostic value object for a single
  federatable Grav page. Carries route, absolute URL, title, rendered
  HTML, published + modified timestamps. Provides a stable 16-hex-char
  id (SHA-256 of route) and a `charCount()` helper that strips HTML +
  decodes entities for the Note/Article threshold check.
- `Outbox\PageSource` — interface over the page collection so the
  outbox can be tested without Grav loaded.
- `Outbox\GravPageSource` — Grav adapter that walks `$grav['pages']`,
  filters routable + published pages under the configured glob prefix
  (`/blog/**` trims to `/blog`), and yields PageRecords in reverse-
  chronological order.
- `Outbox\ActivityTransformer` — translates PageRecord into AS 2.0:
  bare `Note`/`Article` object for content negotiation, plus a
  `Create` activity wrapper for outbox/push. `Create.id` is stable
  per-page (`<url>#create-<published-unix>`) so the same post always
  gets the same activity id.
- `Http\OutboxController` — `GET /activitypub/outbox`. Two modes:
  OrderedCollection summary (totalItems + first/last) by default;
  OrderedCollectionPage with up to 20 items when `?page=true`. Simple
  1-indexed page numbers as the cursor (`p=1`, `p=2`, ...). Invalid
  page numbers are clamped to the valid range.
- `Http\BlogPostNegotiator` — Accept-header parsing and AP-response
  construction for content negotiation on blog post URLs. Accepts
  `application/activity+json` and AS-profiled `application/ld+json`;
  rejects plain `application/json` and `text/html`.
- Plugin entry: dispatcher moved from `onPluginsInitialized` to
  `onPagesInitialized` so the Grav pages collection is actually
  built when our routes engage. Preflight stays on
  `onPluginsInitialized` for fail-fast behaviour. `onPageInitialized`
  now also runs the BlogPostNegotiator: if the resolved page falls
  under the blog filter AND the request Accept matches AP, the AP
  Note/Article is served via `$grav->close()` instead of letting
  Grav render HTML.
- 26 new unit tests (PageRecord, ActivityTransformer,
  OutboxController, BlogPostNegotiator). Suite is now
  **75 tests / 198 assertions**, all green.

### Added (Block 2a — NodeInfo)
- `NodeInfo\NodeInfoBuilder` — assembles the discovery pointer and
  the schema-2.0 instance document. Reports
  `software.name=grav-fediverse-publisher`, `protocols=[activitypub]`,
  `openRegistrations=false`, single-user usage (0 or 1 depending on
  whether an actor is configured), and host metadata (Grav core
  version) in `metadata.host`.
- `Http\NodeInfoDiscoveryController` — `GET /.well-known/nodeinfo`,
  returns the pointer JSON. `Cache-Control: public, max-age=300`.
- `Http\NodeInfoController` — `GET /nodeinfo/2.0`, returns the
  schema-2.0 doc with the spec-recommended profile-parameter in
  `Content-Type`.
- 9 new unit tests (Builder + both controllers); full suite now
  49 tests / 134 assertions.

### Notes

- No federation code yet — controllers, signer, queue worker and inbox
  land in subsequent commits.
- License: MIT.
