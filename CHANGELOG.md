# Changelog

All notable changes to this project are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
