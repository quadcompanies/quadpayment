<?php

namespace QuadCompanies\QuadPayment\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\View\Component;
use QuadCompanies\QuadPayment\Checkout\CheckoutManager;
use QuadCompanies\QuadPayment\Contracts\BotVerifier;
use QuadCompanies\QuadPayment\Exceptions\PaymentBlockedException;
use QuadCompanies\QuadPayment\Models\Checkout;
use QuadCompanies\QuadPayment\Payload\PayloadException;
use QuadCompanies\QuadPayment\Security\Bot\TurnstileVerifier;

/**
 * Payload secure card fields, dropped inside any form:
 *
 *   <form method="POST" action="/donate">
 *       @csrf
 *       ...your own fields...
 *       <x-quadpayment::card-fields :checkout="$checkout" />
 *       <button type="submit">Donate</button>
 *   </form>
 *
 * Card number, expiry and CVC are Payload-hosted iframes; cardholder name and
 * ZIP are read by Payload.js and have no `name` attribute, so none of them
 * are ever posted to your server. Only the checkout reference and the
 * resulting transaction id are.
 */
class CardFields extends Component
{
    public ?string $token = null;

    public ?string $unavailable = null;

    public string $mode;

    public function __construct(
        public Checkout $checkout,
        ?string $mode = null,
        public bool $name = true,
        public bool $zip = true,
        public bool $cvc = true,
        public string $revealLabel = 'Enter card details',
        public string $inputClass = 'qp-input',
        public string $labelClass = 'qp-label',
        public string $groupClass = 'qp-group',
        public string $errorClass = 'qp-error',
        public bool $defaultStyles = true,
    ) {
        $this->mode = $mode ?? config('quadpayment.checkout.token_mode', 'deferred');

        if ($this->checkout->isOpen() && $this->mode === 'eager') {
            try {
                $this->token = app(CheckoutManager::class)->issueToken($this->checkout, app(Request::class));
            } catch (PaymentBlockedException $e) {
                $this->unavailable = $e->getMessage();
            } catch (PayloadException) {
                $this->unavailable = 'Payments are temporarily unavailable. Please try again shortly.';
            }
        } elseif (! $this->checkout->isOpen() && ! $this->checkout->isAuthorized() && ! $this->checkout->isPaid()) {
            $this->unavailable = config('quadpayment.guard.messages.checkout_locked');
        }
    }

    public function turnstileSiteKey(): ?string
    {
        return app(BotVerifier::class) instanceof TurnstileVerifier
            ? config('quadpayment.turnstile.site_key')
            : null;
    }

    public function challengeField(): ?string
    {
        return app(BotVerifier::class)->responseField();
    }

    /** Cache-busting version for the component script. */
    public function scriptVersion(): string
    {
        return substr((string) @md5_file(__DIR__ . '/../../../resources/js/quadpayment.js'), 0, 8);
    }

    public function render(): View
    {
        return view('quadpayment::components.card-fields');
    }
}
