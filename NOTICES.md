# Third-Party Notices

This file records every third-party piece of code or library the
Fediverse Publisher plugin includes or depends on, with its licence
and what we use from it.

## Composer dependencies (shipped at runtime)

- **[landrok/activitypub](https://github.com/landrok/activitypub)** —
  ActivityStreams 2.0 type system + validators + ontology /
  dialect extension points. **MIT.**
  Used as a Composer dependency only. *Not* used for inbound HTTP
  signature verification — that path uses our own
  `Signature\CryptoVerifier` directly against
  `phpseclib3\Crypt\RSA`, per the verifier-sketch round-3
  amendment that found landrok 0.8.1's own HTTP-signature path
  performs an internal network fetch we can't safely intercept.
- **[phpseclib/phpseclib](https://github.com/phpseclib/phpseclib)**
  v3 — RSA key generation, PEM I/O, `SHA256withRSA` signing and
  verification. **MIT.** Used directly by `Keys\KeyStore`,
  `Signature\Signer`, and `Signature\CryptoVerifier`.
- **[guzzlehttp/guzzle](https://github.com/guzzle/guzzle)** v7 —
  HTTP client used for outbound federation pushes
  (`Push\Dispatcher`) and the SSRF-hardened actor-document fetch
  (`Signature\KeyFetcher`). **MIT.**
- **[nyholm/psr7](https://github.com/Nyholm/psr-7)** +
  **[nyholm/psr7-server](https://github.com/Nyholm/psr7-server)** —
  PSR-7 + PSR-17 implementation. **MIT.** Used for response
  construction in every controller and request creation in the
  push signer.

## Ported code

None at runtime. The original ADR-002 §2 plan was to port
`includes/signature/class-http-signature-draft.php` from
`Automattic/wordpress-activitypub` for the outbound signer. In
practice the implementation ended up being ~80 lines of fresh code
(`Signature\Signer` and `Signature\RequestSigner`) written against
the draft-cavage-http-signatures-12 spec directly, so no copyright
attribution from upstream is owed.

The Mastodon and `wordpress-activitypub` source trees were studied
as **architecture references** during design (header set choices,
status-code semantics, key-cache TTL, retry schedule), but no
licensed code from either project was copied into this plugin.

## Dev-only dependencies

These are pulled in by `composer install` for testing / lint /
analyse, but are not shipped at runtime (`composer install --no-dev`).

- **[phpunit/phpunit](https://phpunit.de/)** v10 — testing. MIT.
- **[phpstan/phpstan](https://phpstan.org/)** v1.11 — static
  analysis. MIT.
- **[friendsofphp/php-cs-fixer](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer)**
  v3 — code style. MIT.

## Specifications

The plugin implements the following specifications. They are not
"third party" in the licence sense, but operators may want to refer
back to them:

- W3C ActivityPub Recommendation —
  <https://www.w3.org/TR/activitypub/>
- RFC 7033 (WebFinger) —
  <https://www.rfc-editor.org/rfc/rfc7033>
- IETF draft-cavage-http-signatures-12 (HTTP Signatures) —
  <https://datatracker.ietf.org/doc/html/draft-cavage-http-signatures-12>
- NodeInfo schema 2.0 —
  <http://nodeinfo.diaspora.software/schema.html>
