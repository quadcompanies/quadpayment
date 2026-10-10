{{--
    Payload secure card fields. Place inside your <form>.

    Nothing typed here reaches your server: card number / expiry / CVC are
    Payload-hosted iframes, and the cardholder-name and ZIP inputs have no
    `name` attribute (Payload.js reads them by their pl-input attribute).
    Your form receives only quadpayment_checkout and quadpayment_transaction.
--}}
@php($uid = 'qp-' . substr($checkout->reference, -10))

<div {{ $attributes->merge(['class' => 'qp-card-fields']) }}
     id="{{ $uid }}"
     data-quadpayment
     data-mode="{{ $mode }}"
     data-token-url="{{ route('quadpayment.token') }}"
     data-declined-url="{{ route('quadpayment.declined') }}"
     data-csrf="{{ csrf_token() }}"
     data-challenge-field="{{ $challengeField() }}"
     data-max-declines="{{ config('quadpayment.guard.checkout.max_declines', 3) }}"
     @if($token) data-token="{{ $token }}" @endif
     @if($checkout->isAuthorized() || $checkout->isPaid()) data-authorized @endif
     @if($unavailable) data-unavailable @endif>

    <input type="hidden" name="quadpayment_checkout" value="{{ $checkout->reference }}">
    <input type="hidden" name="quadpayment_transaction" value="{{ $checkout->isAuthorized() || $checkout->isPaid() ? $checkout->transaction_id : '' }}" data-qp-transaction>

    <div class="{{ $errorClass }}" role="alert" aria-live="polite" data-qp-error @if(! $unavailable) hidden @endif>{{ $unavailable }}</div>

    @if($checkout->isAuthorized() || $checkout->isPaid())
        <p class="qp-authorized" data-qp-authorized>
            {{ $checkout->card_brand ? ucfirst($checkout->card_brand) : 'Card' }}
            @if($checkout->card_last4) ending {{ $checkout->card_last4 }} @endif
            — payment of {{ number_format((float) $checkout->amount, 2) }} {{ $checkout->currency }} approved.
        </p>
    @elseif(! $unavailable)
        @if($mode === 'deferred')
            <div class="qp-gate" data-qp-gate>
                @if($turnstileSiteKey())
                    <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey() }}" data-callback="quadpaymentChallengeSolved" data-qp-challenge></div>
                @endif
                <button type="button" class="qp-reveal" data-qp-reveal>{{ $revealLabel }}</button>
            </div>
        @endif

        <div class="qp-fields" data-qp-fields @if($mode === 'deferred') hidden @endif>
            @if($name)
                <div class="{{ $groupClass }}">
                    <label class="{{ $labelClass }}" for="{{ $uid }}-name">Name on card</label>
                    <input id="{{ $uid }}-name" type="text" class="{{ $inputClass }}" pl-input="account_holder"
                           autocomplete="cc-name" maxlength="100" required data-qp-required>
                </div>
            @endif

            <div class="{{ $groupClass }}">
                <label class="{{ $labelClass }}">Card number</label>
                <div class="{{ $inputClass }}" pl-input="card_number"></div>
            </div>

            <div class="qp-row">
                <div class="{{ $groupClass }}">
                    <label class="{{ $labelClass }}">Expiration</label>
                    <div class="{{ $inputClass }}" pl-input="expiry"></div>
                </div>
                @if($cvc)
                    <div class="{{ $groupClass }}">
                        <label class="{{ $labelClass }}">Security code</label>
                        <div class="{{ $inputClass }}" pl-input="cvc"></div>
                    </div>
                @endif
            </div>

            @if($zip)
                <div class="{{ $groupClass }}">
                    <label class="{{ $labelClass }}" for="{{ $uid }}-zip">Billing ZIP code</label>
                    <input id="{{ $uid }}-zip" type="text" class="{{ $inputClass }}" pl-input="billing_address[postal_code]"
                           autocomplete="postal-code" inputmode="numeric" maxlength="10" pattern="[A-Za-z0-9 \-]{3,10}" required data-qp-required>
                </div>
            @endif
        </div>
    @endif
</div>

@once
    @if($defaultStyles)
        <style>
            .qp-card-fields { display: grid; gap: .75rem; }
            .qp-fields { display: grid; gap: .75rem; }
            .qp-fields[hidden], .qp-gate[hidden], [data-qp-error][hidden] { display: none; }
            .qp-row { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
            .qp-label { display: block; font-size: .875rem; font-weight: 600; margin-bottom: .35rem; }
            .qp-input { display: block; width: 100%; box-sizing: border-box; min-height: 2.75rem; padding: .65rem .75rem; border: 2px solid #d1d5db; border-radius: .6rem; background: #fff; font: inherit; }
            .qp-input:focus, .qp-input:focus-within { outline: none; border-color: #1e3a8a; }
            .qp-error { padding: .65rem .85rem; border: 1px solid #fecaca; border-radius: .6rem; background: #fef2f2; color: #b91c1c; font-size: .875rem; }
            .qp-authorized { padding: .65rem .85rem; border: 1px solid #bbf7d0; border-radius: .6rem; background: #f0fdf4; color: #166534; font-size: .875rem; }
            .qp-reveal { padding: .7rem 1rem; border: 2px solid #1e3a8a; border-radius: 999px; background: #fff; color: #1e3a8a; font-weight: 600; cursor: pointer; }
            .qp-reveal[disabled] { opacity: .6; cursor: progress; }
            [pl-invalid], .qp-input[pl-invalid] { border-color: #ef4444 !important; background-color: #fef2f2; }
        </style>
    @endif
    <script src="{{ config('quadpayment.payload.js_url') }}"></script>
    @if($mode === 'deferred' && $turnstileSiteKey())
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif
    <script src="{{ route('quadpayment.script') }}?v={{ $scriptVersion() }}" defer></script>
@endonce
