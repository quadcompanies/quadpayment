# Card-testing defenses

## The threat

In card testing (also called an enumeration attack), someone holding stolen card numbers uses your payment form to find out which cards still work. They run small charges, often a donation minimum, and automate the process so they can try hundreds of cards an hour. Every attempt costs a processing fee. A high decline rate puts your merchant account at risk: Visa's Acceptance Monitoring Program (VAMP) counts enumeration explicitly. Each working card the attacker finds becomes a fraudulent charge somewhere else.

Several patterns tell a card tester apart from a real customer:

| Signal | Real customer | Card tester |
|---|---|---|
| Different cards per person | 1, sometimes 2 | Dozens |
| Decline reasons | Insufficient funds, a typo in the CVV or ZIP, then success | Invalid number, CVV or expiry, over and over |
| Attempts on one card | A few retries, close together | Many, sometimes spread across IPs (CVV/ZIP guessing) |
| Site-wide decline rate | Low single-digit percent | Spikes sharply, often across many IPs at once |
| Path through the site | Loads the page and fills in the form | Reuses a harvested token straight against the processor API |

The last row matters most. Once an attacker has a client token, they never need to call your endpoints again. They can post card after card directly to Payload. So any defense that depends on the browser reporting failures, like the old `track-attempt` endpoint, cannot be relied on. Defenses have to sit where the attacker can't get around them:

- **Before token minting.** Control who gets a client token.
- **In Payload's webhooks.** Count what actually happened at the processor.
- **At capture time.** Refuse to keep money taken during an attack.

## The layers

Each layer is drawn from common industry guidance: Visa and Mastercard enumeration guidance, PCI SSC, and processor anti-fraud playbooks. The thresholds are defaults, and every one can be changed in `config/quadpayment.php` or at runtime with `quadpayment:set`.

### 1. Gate the ammunition: client tokens

- **Bot challenge before minting.** In the default `deferred` mode, the card fields stay hidden until the visitor passes Cloudflare Turnstile, which is checked server-side and fails closed. Loading the page creates no token and no Payload customer.
- **Server-fixed intent.** Each token comes from a Payload `payment_form` Intent that fixes the amount, payer account and order number. A harvested token can't be used to choose a different amount.
- **Token velocity limits:**

  | Scope | Limit |
  |---|---|
  | IP | 5 per hour |
  | /24 subnet | 15 per hour |
  | Session | 4 per hour |
  | Email | 8 per day |

  An IP that requests twice its limit is blocked outright.
- **Site-wide surge ceiling.** More than 120 tokens in 10 minutes trips the breaker.
- **Short token lifetime.** Tokens live 10 minutes and are reused on reload rather than re-minted.

### 2. Per-checkout lock

After **3 declines** on one checkout, the checkout locks and its intent is revoked. The customer sees "please start over", which means passing the bot challenge again for a new token. A real customer fixing typos rarely gets this far, and if they do they can simply start over.

### 3. Per-actor and per-card limits, with escalating blocks

Declines are **weighted**. Codes that suggest the payer doesn't hold the card count 1.0: `invalid_card_number`, `invalid_card_code`, `card_expired`, `invalid_zip`, `invalid_address`, `suspicious_activity`, `too_many_attempts` and `general_decline`. Codes that real customers hit count 0.25: `insufficient_bal`, `exceeded_limit` and `duplicate_attempt`.

| Rule | Default | Blocks |
|---|---|---|
| Weighted declines per IP | 5 per hour | IP |
| Weighted declines per /24 (or /64 for IPv6) | 10 per hour | subnet |
| Weighted declines per email | 5 per day | email |
| Weighted declines per card | 3 per day | card |
| **Distinct cards per IP** | **more than 3 per day** | IP |
| **Distinct cards per email** | **more than 3 per day** | email |
| **Distinct cards per session** | **more than 3** | session |
| Distinct cards per customer account | more than 4 per day | account |
| One card used from many IPs | more than 3 IPs per day | card |

The distinct-card rules are the strongest single signal, and they count *successful* attempts as well as declines. A tester who finds a few good cards in a row is still caught.

**Escalation.** A first block lasts 1 hour, a second lasts 24 hours, and a third or later lasts 7 days. When an actor is blocked, all of their open checkouts are locked and their tokens revoked.

**Identity is folded** so trivial variations don't reset the limits:

- **Email.** `John.Smith+1@gmail.com` and `johnsmith@gmail.com` count as the same address.
- **IP.** IPv4 is grouped by /24 and IPv6 by /64.
- **Session.** The session id survives Laravel's session-id rotation.

Counts come from database tables, not the cache, so a cache flush doesn't reset them. Trusted IPs, such as your office or a load tester, can be exempted with `QUADPAYMENT_ALLOW_IPS`: they are never blocked or rate limited, and their attempts are recorded but never counted — they cannot lock checkouts, create blocks, trip the breaker, or inflate the stats of a card or subnet they share with real visitors. A breaker tripped by other traffic still stops them.

### 4. Circuit breaker: stop all card processing

Within a 15-minute window, the breaker trips on any one of these:

- A weighted decline score of **12** or more.
- A decline ratio of **60%** or more, once there are at least **10** attempts with declines coming from **3 or more IPs**. The 3-IP condition keeps one unlucky customer on a quiet site from tripping it.
- **8** or more different cards declined.

When the breaker trips:

- No new tokens are minted.
- Every live token is revoked.
- The card fields render as "Online payments are temporarily unavailable."
- Any charge that arrives anyway is **voided, not kept**.
- An email goes to `QUADPAYMENT_ALERT_EMAIL` and the `CircuitBreakerTripped` event fires, so you can hook up Slack or SMS.

The breaker resets itself after 60 minutes. Set `breaker.cooldown_minutes` to `0` to require a manual reset with `php artisan quadpayment:breaker reset`. Its state lives in the database, so clearing the cache cannot reopen payments in the middle of an attack.

### 5. Enforcement at capture

The hosted form only supports `capture=auto` (Payload.js rejects an authorize-only payment template), so the card is charged when the customer submits. The server then decides whether to *keep* the money: the charge is voided — reversed before settlement, so it never posts — if any of these is true when the transaction arrives, by webhook or at `verify()`:

- the checkout is locked
- the card is blocked
- the requester is blocked
- the breaker is tripped

This closes the last gap: an attacker who used a token directly against Payload before we could revoke it.

### 6. Trustworthy signals

- **Signed webhooks.** Webhooks must carry a valid `X-Payload-Signature`, an HMAC-SHA256 of the raw body using `sender_secret`. Unsigned or mis-signed posts are rejected.
- **Idempotent.** Each `event_id` is processed once. Webhooks, browser reports and verification are reconciled by transaction id, so each attempt is counted once.
- **Re-fetched.** The webhook body and browser reports are treated as pointers. Every transaction is re-fetched from the Payload API before it is counted, so a forged request can't block a legitimate customer or trip the breaker.
- **Real origin IP.** Payload's `initiator.ip` is used where it's available. This is the IP that actually reached Payload, which may differ from the IP that loaded your page.

## What each person experiences

**A real customer who mistypes a CVV:** they see "declined, try again", fix it, and pay. Nothing is blocked.

**A real customer whose card has no funds:** they see the decline, and each one weighs only 0.25. They try another card and pay. Nothing is blocked.

**A real customer having a very bad day** (three declines, then a fresh checkout and a different card): they pay. A test covers exactly this scenario (`OperationsTest`).

**A card tester:**

1. They run out of tokens after 5 per hour, and need a solved challenge for each one.
2. They are locked out of a checkout after 3 declines.
3. Their IP is blocked at the 4th distinct card.
4. Rotating IPs runs into the subnet limit and the per-card limits.
5. A distributed attack trips the breaker, which ends it for everyone until a human looks or the cooldown passes. This is acceptable by design.

## Settings to enable on the Payload account

These are processor-side controls listed under Processing Settings in the API reference. They reinforce the layers above.

| Setting | Recommendation |
|---|---|
| `card_processing.features.avs.enabled` | **On.** Use a `threshold` that requires at least a ZIP match. The component always collects the ZIP. |
| CVV | Keep the `cvc` field on. This is the default, and the most effective check against enumeration. |
| `card_processing.limits.trans_limit` | Set to your realistic maximum. |
| `card_processing.allowed_card_types.prepaid` | Consider **off** if your use case allows it. Prepaid cards are disproportionately used in testing. |
| Minimum amount | Set `quadpayment.checkout.min_amount` as high as your business allows. Testers favor tiny charges, and the donation flow's $25 minimum already helps. |
| Payload Polygraph | Payload's own fraud and risk layer carries over to v2. Ask Payload to review its rules for your account. |

## PCI scope

- **Card data never reaches your server.** Card number, expiry and CVC are entered into Payload-hosted iframes. The cardholder name and ZIP are read by Payload.js and have no `name` attribute, so they are never posted to your server. A test (`ComponentTest`) asserts this.
- **Nothing card-related is stored.** The only card-related values stored locally are brand and last four digits (allowed as a truncated PAN) and an irreversible, keyed card hash used for limits. No PAN, CVV or full expiry+PAN pair is ever stored, logged or held in memory.
- **Secrets stay server-side.** The Payload secret key never leaves the server. The browser only gets a short-lived, single-intent client token.
- **PCI DSS 4.0 payment-page scripts.** Requirements 6.4.3 and 11.6.1 cover scripts on payment pages. The payment page loads three scripts: Payload.js, Turnstile and this package's `quadpayment.js`. The package serves `quadpayment.js` itself from your domain. Keep an inventory of these scripts and consider a Content Security Policy that allows only these sources.

## Compared with the previous implementation

| Previous behavior | Risk | Now |
|---|---|---|
| `usleep()` progressive delay | Each delayed request ties up a PHP worker, so an attacker could use it to take the site down | No tarpits. Requests are refused outright. |
| Counters in the cache | A cache flush or deploy resets every limit and the breaker | Database tables |
| Webhook authenticated by a secret in the URL path | The secret leaks into access logs | HMAC signature, as Payload specifies |
| Failed attempts counted in `track-attempt` from the browser | A scripted attacker never calls it | Webhook-driven counting, with the browser report only as a hint |
| 5 declines on one card tripped the **global** breaker | One customer retrying their own card could shut down the site | Blocks only that card. The breaker needs site-wide signals. |
| Fixed 10 declines in 10 minutes, site-wide | Too sensitive on a busy day, and blind to slow attacks | Weighted score, ratio and distinct-card rules |
| All declines counted equally | Insufficient-funds declines from real customers counted as heavily as CVV guessing | Weighted by decline code |
| No limit on distinct cards per person | Missed the strongest signal of all | Per IP, email, session and account |
| Breaker left issued tokens alive | Attacks continued with tokens already harvested | Live tokens revoked, and late successes voided |
| Suspicious successes kept | Money kept even when the attempt was suspicious | Charge voided unless every check passes |
