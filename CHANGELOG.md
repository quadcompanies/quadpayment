# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/) and the package uses
[Semantic Versioning](https://semver.org/). While the version is 0.x, minor
releases may contain breaking changes.

## [0.3.1] - 2026-10-23

### Fixed
- Card submission did nothing in Firefox: the form flipped its button to
  "Processing…" and hung, with no network request to Payload and no
  success/declined event. The component triggered Payload.js by calling
  `e.stopImmediatePropagation()` on the user's submit and then re-dispatching a
  hand-built `new Event('submit')`. That synthetic event is untrusted
  (`isTrusted === false`); Chrome's Payload.js processed it anyway, but
  Firefox's ignored it, so the card was never tokenized. The submit handler now
  lets the user's real, trusted submit event continue through to Payload.js
  instead of stopping it and faking one — Payload prevents the native
  navigation itself (`preventDefaultOnSubmit: true`). No app changes are
  required. (The internal `paying` phase, which only existed to pass the
  re-dispatched event through, is gone; the state machine is now
  idle → processing → done.)

## [0.3.0] - 2026-10-11

Breaking release: the default capture mode changed and `manual` capture is
now rejected.

### Fixed
- Manual capture could never complete through the hosted `<x-quadpayment::card-fields>`
  form and now fails loudly instead of minting a dead client token. The
  `payment_form` intent requires `payment_template.status` to be an object
  (`{"value":"authorized"}`), which Payload accepts — but Payload.js then
  forwards that same object into the browser's `POST /transactions` call, which
  rejects it with `InvalidAttributes: status "Value cannot be an object"`. No
  request shape satisfies both endpoints, so a `capture: "manual"` checkout
  produced a card form that silently did nothing on submit.

### Changed
- **The default capture mode is now `auto`** (was `manual`). Auto capture is the
  only mode the hosted form supports.
- `->capture('manual')` and a `QUADPAYMENT_CAPTURE=manual` default now throw
  `InvalidArgumentException` with an explanation. To decide after the submit
  whether to keep the money, use `QuadPayment::verify($request, capture: false)`,
  then `$result->capture()` to confirm or `$result->void()` to reverse the
  charge. Note the semantics differ from manual capture: the card is charged
  on submit, and a void reverses that charge before settlement — there is no
  authorization hold.
- The test fake (`FakePayload::payIntent`) now rejects a transaction built from a
  template carrying a `status` object, mirroring the real API, so this class of
  bug cannot pass the suite again.

## [0.2.0] - 2026-10-11

### Added
- Development options: testing IPs in `QUADPAYMENT_ALLOW_IPS` are now fully
  exempt from the guard — besides bypassing per-actor limits (as before),
  their attempts no longer lock checkouts, create card blocks, feed the
  circuit breaker or count against other visitors, so repeated card tests
  can run without tripping anything. `QUADPAYMENT_WEBHOOK_VERIFY=false`
  accepts unsigned webhooks for local replay; it is ignored in production.
  `QUADPAYMENT_DEBUG=true` logs every Payload API call, guard decision and
  checkout milestone at debug level, prefixed `quadpayment.debug:`.

## [0.1.1] - 2026-10-10

### Fixed
- Every GET and DELETE request to the Payload API failed with
  `400: The browser (or proxy) sent a request that this server could not
  understand` -- including the transaction fetch inside `verify()`, which made
  all real payment verification impossible. The client declared
  `Content-Type: application/json` on bodyless requests, which Payload's API
  rejects; it now only does so on POST/PUT/PATCH. The test fake is now as
  strict as the real API about this.

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

[0.3.0]: https://github.com/quadcompanies/quadpayment/releases/tag/v0.3.0
[0.2.0]: https://github.com/quadcompanies/quadpayment/releases/tag/v0.2.0
[0.1.1]: https://github.com/quadcompanies/quadpayment/releases/tag/v0.1.1
[0.1.0]: https://github.com/quadcompanies/quadpayment/releases/tag/v0.1.0
