# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/) and the package uses
[Semantic Versioning](https://semver.org/). While the version is 0.x, minor
releases may contain breaking changes.

## [0.1.0] - 2026-10-10

First release, for validation against the Payload sandbox and a staging app.
Not yet verified against the live Payload API — see "Things to confirm in the
Payload sandbox" in the README.

### Added
- `<x-quadpayment::card-fields>` Blade component: Payload secure fields
  (cardholder name, card number, expiry, CVC, ZIP) inside any form, in
  deferred (challenge-gated) or eager token mode.
- `QuadPayment::checkout()` builder and `QuadPayment::verify()` server-side
  verification with manual (authorize, then capture) or automatic capture.
- Payload customer accounts and saved cards: `for()`, `saveCard()`,
  `customers()->paymentMethods()`, `chargeSavedCard()`.
- Card-testing guard: token velocity limits, per-checkout decline lock,
  weighted decline and distinct-card limits with escalating blocks,
  site-wide circuit breaker, voiding of suspicious authorizations.
- Signed (HMAC-SHA256), idempotent Payload webhook receiver.
- Artisan commands: `quadpayment:status`, `:breaker`, `:block`, `:set`,
  `:webhooks`, `:prune`.

[0.1.0]: https://github.com/quadcompanies/quadpayment/releases/tag/v0.1.0
