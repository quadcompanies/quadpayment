<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payload API
    |--------------------------------------------------------------------------
    |
    | The secret key never leaves the server. The browser only ever receives a
    | short-lived client token minted from an Intent, and card data is entered
    | into Payload-hosted secure fields — it never touches this application.
    |
    */

    'payload' => [
        'api_key' => env('PAYLOAD_API_SECRET'),
        'base_url' => env('PAYLOAD_API_URL', 'https://api.payload.com'),
        'api_version' => env('PAYLOAD_API_VERSION', 'v2.0'),
        'processing_account_id' => env('PAYLOAD_ACCOUNT_ID'),
        'timeout' => 15,
        'js_url' => env('PAYLOAD_JS_URL', 'https://payload.com/Payload.js'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkout behaviour
    |--------------------------------------------------------------------------
    |
    | capture: "auto"   — the card is charged when the customer submits.
    |          "manual" — the card is only authorized; QuadPayment::verify()
    |                     captures it once your controller is happy with the
    |                     rest of the form. Uncaptured authorizations are voided
    |                     by `quadpayment:prune`. Recommended for forms that
    |                     collect other data alongside the card.
    |
    | token_mode: "deferred" — the Payload client token is only minted after
    |                          the visitor passes the bot check and the guard,
    |                          when they click to reveal the card fields.
    |             "eager"    — the token is minted when the page renders. Only
    |                          use this behind an earlier gate (a prior
    |                          challenge-protected step, or an authenticated
    |                          user) — otherwise bots can farm tokens by
    |                          reloading the page.
    |
    */

    'checkout' => [
        'capture' => env('QUADPAYMENT_CAPTURE', 'manual'),
        'token_mode' => env('QUADPAYMENT_TOKEN_MODE', 'deferred'),
        'token_ttl_minutes' => 10,
        'checkout_ttl_minutes' => 60,
        'authorization_void_after_minutes' => 60,
        'currency' => 'USD',
        'min_amount' => 1.00,
        'max_amount' => 10000.00,
        'route_prefix' => 'quadpayment',
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bot verification
    |--------------------------------------------------------------------------
    |
    | Required before a client token is minted in deferred mode. "turnstile"
    | verifies Cloudflare Turnstile server-side; "null" disables the check
    | (local development and tests only).
    |
    */

    'bot_verifier' => env('QUADPAYMENT_BOT_VERIFIER', 'turnstile'),

    // Development only: log every Payload API call, guard decision and
    // checkout milestone at debug level ("quadpayment.debug: ..."). The
    // context includes full API responses (customer names, emails) — don't
    // feed production logs with it.
    'debug' => (bool) env('QUADPAYMENT_DEBUG', false),

    'turnstile' => [
        'secret' => env('TURNSTILE_SECRET_KEY'),
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'response_field' => 'cf-turnstile-response',
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Payload signs each delivery with HMAC-SHA256 of the raw body using the
    | sender_secret (X-Payload-Signature). Unsigned or mis-signed requests are
    | rejected. Register with `php artisan quadpayment:webhooks`.
    |
    */

    'webhooks' => [
        'secret' => env('PAYLOAD_WEBHOOK_SECRET'),

        // Development escape hatch: set QUADPAYMENT_WEBHOOK_VERIFY=false to
        // accept webhooks without a valid signature (e.g. replaying sandbox
        // events against localhost with curl). Ignored in production:
        // signatures are always enforced there.
        'verify' => (bool) env('QUADPAYMENT_WEBHOOK_VERIFY', true),

        'path' => 'quadpayment/webhook',
        'triggers' => ['payment', 'processed', 'authorized', 'decline', 'reject', 'void'],
        'retries' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Card-testing defenses
    |--------------------------------------------------------------------------
    |
    | Limits are evaluated against the qp_attempts / qp_checkouts tables, so
    | they survive cache flushes and cannot be reset by clearing cookies. A
    | legitimate customer fixing a typo stays well inside every limit; a
    | script cycling through a list of cards hits several at once.
    |
    | "declines" only count decline codes that signal bad card data (see
    | decline_codes.suspicious). Insufficient funds, over-limit and duplicate
    | declines are recorded but weigh less — they are what real customers hit.
    |
    */

    'guard' => [
        'enabled' => env('QUADPAYMENT_GUARD', true),

        // Group IPv4 by /24 and IPv6 by /64 for subnet-level limits — attackers
        // rotate through addresses in the same block.
        'ipv4_subnet' => 24,
        'ipv6_subnet' => 64,

        // Testing IPs (office, CI, load tester): never rate limited, never
        // blocked, and their attempts — still recorded in qp_attempts — don't
        // lock checkouts, create blocks or feed the circuit breaker, so a
        // developer can run card tests all day without tripping anything.
        // A breaker tripped by *other* traffic still stops these IPs too.
        'allow_ips' => array_filter(explode(',', (string) env('QUADPAYMENT_ALLOW_IPS', ''))),

        // Client tokens are the ammunition for card attempts.
        'tokens' => [
            'per_ip_per_hour' => 5,
            'per_subnet_per_hour' => 15,
            'per_email_per_day' => 8,
            'per_session_per_hour' => 4,
            'global_per_10_minutes' => 120,   // site-wide anomaly ceiling
        ],

        'checkout' => [
            'max_declines' => 3,              // per checkout; then the visitor must start over
        ],

        // Weighted decline score: suspicious codes count 1.0, benign 0.25.
        'declines' => [
            'per_ip_per_hour' => 5,
            'per_subnet_per_hour' => 10,
            'per_email_per_day' => 5,
            'per_card_per_day' => 3,          // same card from anywhere — CVV/ZIP enumeration
        ],

        // The strongest single signal: one actor presenting many different cards.
        'distinct_cards' => [
            'per_ip_per_day' => 3,
            'per_email_per_day' => 3,
            'per_session' => 3,
            'per_account_per_day' => 4,       // saved-card customers adding cards
        ],

        // One card used from many places at once is distributed testing.
        'card_spread' => [
            'max_ips_per_card_per_day' => 3,
        ],

        // Block durations escalate with repeat offenses (minutes).
        'block_minutes' => [60, 1440, 10080],

        // Site-wide circuit breaker — stops ALL card processing.
        'breaker' => [
            'window_minutes' => 15,
            'max_declines' => 12,             // absolute suspicious declines in window
            'min_sample' => 10,               // ratio rule needs at least this many attempts,
            'max_decline_ratio' => 0.6,       // this share of them declined,
            'ratio_min_sources' => 3,         // from at least this many different IPs
            'max_distinct_declined_cards' => 8,
            'cooldown_minutes' => 60,         // auto-reset after; 0 = manual reset only
        ],

        'alert' => [
            'mail_to' => env('QUADPAYMENT_ALERT_EMAIL', env('MAIL_TO_EMAIL')),
        ],

        'messages' => [
            'breaker' => 'Online payments are temporarily unavailable. Please try again later.',
            'blocked' => 'Too many payment attempts. Please try again later.',
            'checkout_locked' => 'Too many failed payment attempts. Please start over.',
        ],
    ],

    'decline_codes' => [
        // Signals that the person does not actually hold the card.
        'suspicious' => [
            'invalid_card_number', 'invalid_card_code', 'card_expired', 'invalid_zip',
            'invalid_address', 'suspicious_activity', 'too_many_attempts', 'general_decline',
        ],
        // Real cardholders hit these; record them but weigh them lightly.
        'benign' => ['insufficient_bal', 'exceeded_limit', 'duplicate_attempt'],
        'benign_weight' => 0.25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */

    'table_prefix' => 'qp_',
    'connection' => env('QUADPAYMENT_DB_CONNECTION'),

    // Rows older than this are deleted by `quadpayment:prune`.
    'retention_days' => 90,
];
