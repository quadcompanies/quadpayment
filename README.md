# quadpayment

Tokenized [Payload](https://payload.com) v2 card payments for Laravel 13, with card-testing defenses built in.

- **Card fields component.** You drop `<x-quadpayment::card-fields>` into any form. It renders cardholder name, card number, expiry, CVC and ZIP. Card data goes straight to Payload's secure fields. Your app never receives or stores it.
- **Server-side verification.** `QuadPayment::verify($request)` confirms with Payload that the payment is real, is for the right amount and belongs to this checkout. It then captures the payment.
- **Saved cards.** Customers can have Payload customer accounts and saved cards (stored only at Payload).
- **Card-testing guard.** It applies velocity limits, escalating blocks and a site-wide circuit breaker. See [SECURITY.md](SECURITY.md).

## Install

```bash
composer require quadcompanies/quadpayment
php artisan migrate
php artisan vendor:publish --tag=quadpayment-config   # optional
```

`.env`:

```dotenv
PAYLOAD_API_SECRET=secret_key_...
PAYLOAD_ACCOUNT_ID=acct_...            # your processing account (receiver)
PAYLOAD_WEBHOOK_SECRET=                # openssl rand -hex 32
TURNSTILE_SITE_KEY=...
TURNSTILE_SECRET_KEY=...
QUADPAYMENT_ALERT_EMAIL=ops@example.org
```

Register the webhooks and schedule the pruner:

```bash
php artisan quadpayment:webhooks
```

```php
// routes/console.php
Schedule::command('quadpayment:prune')->everyFiveMinutes();
```

The webhook route is `POST /quadpayment/webhook`. It is authenticated by HMAC signature, not by CSRF. If your app has a global CSRF middleware outside the `web` group, exclude that path.

## Usage

### 1. Define the payment when rendering the form

```php
$checkout = QuadPayment::checkout('donation')   // purpose: one open checkout per purpose per session
    ->amount(50)
    ->description('Donation')
    ->name($name)                                // optional, goes on the Payload customer
    ->email($email)                              // optional, enables per-email limits
    ->metadata(['campaign' => 'spring'])         // optional, returned by $payment->metadata()
    ->create($request);
```

The amount is fixed here, server-side, and the browser can't change it. Reloading the page reuses the same checkout and client token.

### 2. Put the fields in your form

```blade
<form method="POST" action="{{ route('donate.complete') }}">
    @csrf
    <input name="full_name" required>          {{-- your own fields, anything --}}
    <x-quadpayment::card-fields :checkout="$checkout" />
    <button type="submit">Donate</button>
</form>
```

When the customer submits, the following happens:

1. Your form's HTML5 validation runs.
2. Payload processes the card.
3. The form posts normally to your controller with all of your fields, plus `quadpayment_checkout` and `quadpayment_transaction`.

None of the card inputs has a `name` attribute, so your server never receives card data.

Component options:

| Attribute | Default | |
|---|---|---|
| `mode` | `deferred` | `deferred`: the card fields appear after the visitor passes Turnstile. `eager`: the client token is minted on render. Use `eager` only behind an earlier challenge or a login. |
| `:name` / `:zip` / `:cvc` | `true` | Show the cardholder name / ZIP / CVC fields |
| `input-class`, `label-class`, `group-class`, `error-class` | `qp-*` | Your CSS classes (Tailwind etc.) |
| `:default-styles` | `true` | Include the small built-in stylesheet |
| `reveal-label` | `Enter card details` | Label of the deferred-mode button |

The component emits these DOM events: `quadpayment:ready`, `quadpayment:success`, `quadpayment:declined`, `quadpayment:locked`, `quadpayment:blocked` and `quadpayment:error`. Use only one card-fields component per page.

### 3. Verify in your controller

```php
use QuadCompanies\QuadPayment\Exceptions\{PaymentBlockedException, PaymentVerificationException};

$data = $request->validate([...]);              // validate your own fields first

try {
    $payment = QuadPayment::verify($request);   // verifies with Payload, then captures
} catch (PaymentBlockedException|PaymentVerificationException $e) {
    return back()->withInput()->withErrors(['payment' => $e->getMessage()]);
}

Order::create($data + ['transaction_id' => $payment->transactionId()]);
```

The `capture` setting defaults to `manual`. In this mode the browser only *authorizes* the card, and `verify()` captures it. As a result:

- **Validation fails first.** If your validation fails before `verify()`, the card has only been authorized. The re-rendered form keeps that authorization and shows "Visa ending 4242 approved". The customer is never charged twice.
- **Your own save step might fail.** Use `verify($request, capture: false)`. Then call `$payment->capture()` after you save, or `$payment->void()` if saving failed.
- **The customer abandons the payment.** `quadpayment:prune` voids authorizations that were never captured, after 60 minutes.

`verify()` is idempotent, so a double submit returns the same result.

What `verify()` checks: the transaction is a successful `payment`, its `order_number` is this checkout's reference, the payer is this checkout's Payload account, the receiver is your account and the amount matches to the cent. It also checks that the transaction isn't already used by another checkout and that the checkout belongs to the current session. If the guard has blocked the card or the actor in the meantime, or the breaker is tripped, the authorization is voided rather than captured.

### Saved cards

```php
// Pay and keep the card on the user's Payload customer account
QuadPayment::checkout('membership')->amount(20)->for($user)->saveCard()->create($request);

// List saved cards. They are fetched live from Payload and never stored locally.
QuadPayment::customers()->paymentMethods($user);   // [['id','brand','last4','expiry','holder'], ...]

// Charge one server-side. Ownership is checked against Payload and the guard still applies.
QuadPayment::chargeSavedCard($user, $paymentMethodId, fn ($c) => $c->amount(20)->description('Renewal'));

QuadPayment::customers()->forOwner($user);          // create the Payload customer explicitly
QuadPayment::customers()->removePaymentMethod($user, $paymentMethodId);
```

### Other helpers

```php
QuadPayment::available();   // false while the circuit breaker is tripped — hide the payment UI
```

Events you can listen for: `PaymentSucceeded`, `PaymentDeclined`, `ActorBlocked`, `CircuitBreakerTripped` (use it for Slack or SMS paging; an email is sent by default) and `CircuitBreakerReset`.

Bot check: the default is Cloudflare Turnstile. To use something else, bind your own `QuadCompanies\QuadPayment\Contracts\BotVerifier`. `QUADPAYMENT_BOT_VERIFIER=null` disables the check, for local development only.

## Operations

```bash
php artisan quadpayment:status                     # attempts, declines, top IPs, recent security events
php artisan quadpayment:breaker [status|trip|reset]
php artisan quadpayment:block list
php artisan quadpayment:block add ip 203.0.113.9 --minutes=1440
php artisan quadpayment:block add email bad@example.com          # hashed automatically
php artisan quadpayment:block add subnet 203.0.113.0/24
php artisan quadpayment:block remove ip 203.0.113.9
php artisan quadpayment:set                         # show guard config
php artisan quadpayment:set declines.per_ip_per_hour 3   # tighten during an attack, no deploy
php artisan quadpayment:set declines.per_ip_per_hour --reset
php artisan quadpayment:webhooks [--list]
```

## Tables

All tables use the `qp_` prefix, which is configurable.

| Table | Holds |
|---|---|
| `qp_checkouts` | Server-side state of each payment: amount, status, Payload account and intent, the hashed requester identity and decline counters |
| `qp_tokens` | Every client token minted. Token velocity limits are counted from this table. |
| `qp_attempts` | Every card transaction Payload reported (by webhook, verification or a re-fetched client report). Decline and distinct-card limits are counted from this table. |
| `qp_blocks` | Bans on IPs, subnets, email hashes, card hashes, sessions or accounts. Each ban has an expiry and a strike count. |
| `qp_security_events` | Audit trail of denials, blocks, breaker trips, failed verifications and rejected webhooks |
| `qp_settings` | Circuit breaker state and runtime threshold overrides |
| `qp_webhook_events` | Webhook `event_id`s, for idempotency |
| `qp_customers` | Maps your models to their Payload customer accounts |

**No table contains card data.** A card is identified only by an HMAC (keyed with `APP_KEY`) of brand, last 4 digits and expiry, which is used for velocity limits. `qp_checkouts` keeps `card_brand` and `card_last4` for receipts. A truncated PAN like this is not cardholder data under PCI DSS. Emails used for limits are stored as keyed hashes.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The test suite runs against a stateful fake of the Payload API.

## Things to confirm in the Payload sandbox before going live

The package follows the v2 API reference (Intents, `/accounts`, `sender`/`receiver`, `status.value`, `X-Payload-Signature`). The reference doesn't cover Payload.js or every write semantic, so check these with test keys:

1. **Manual capture.** Check that `payment_form.payment_template.status.value = "authorized"` produces an authorization that `PUT /transactions/{id} {"status":{"value":"processed"}}` captures. If it doesn't, set `QUADPAYMENT_CAPTURE=auto`.
2. **Payload.js options and events.** Check that `Payload.Form({autosubmit: false})` and the `success`/`processed`/`authorized`/`declined` events behave as the component expects. The component listens for all of these names.
3. **Revoking an intent.** Check that `DELETE /intents/{id}` stops further use of that intent's client token. If it doesn't, the defense still works: anything that succeeds on a locked checkout is voided, by the webhook or at verification.
4. **Saved cards.** Check how `keep_active` / `status: inactive` behave on payment methods.
5. **Webhook signature.** Check that `X-Payload-Signature` is a hex HMAC of the raw body.

Test cards are listed at <https://docs.payload.com/v2/pay/test-cards>. For example, `4111 1111 1111 9905` returns `invalid_card_code`, which is useful for exercising the guard.
