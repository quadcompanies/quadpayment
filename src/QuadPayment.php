<?php

namespace QuadCompanies\QuadPayment;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use QuadCompanies\QuadPayment\Checkout\CheckoutBuilder;
use QuadCompanies\QuadPayment\Checkout\CheckoutManager;
use QuadCompanies\QuadPayment\Checkout\PaymentResult;
use QuadCompanies\QuadPayment\Customers\CustomerManager;
use QuadCompanies\QuadPayment\Models\Checkout;
use QuadCompanies\QuadPayment\Security\CircuitBreaker;
use QuadCompanies\QuadPayment\Security\Fingerprinter;
use QuadCompanies\QuadPayment\Security\Guard;

/**
 * Entry point (also available as the QuadPayment facade).
 *
 *   // Render: define the payment server-side
 *   $checkout = QuadPayment::checkout('donation')->amount(50)->description('Donation')->create();
 *   // <x-quadpayment::card-fields :checkout="$checkout" /> inside your form
 *
 *   // Submit: verify against Payload (and capture)
 *   $payment = QuadPayment::verify($request);
 *   Donation::create([... 'transaction_id' => $payment->transactionId()]);
 */
class QuadPayment
{
    public function __construct(
        private CheckoutManager $checkouts,
        private CustomerManager $customers,
        private Guard $guard,
        private Fingerprinter $fingerprinter,
    ) {}

    public function checkout(string $purpose = 'default'): CheckoutBuilder
    {
        return new CheckoutBuilder($this->checkouts, $this->fingerprinter, (array) config('quadpayment.checkout'), $purpose);
    }

    /** Mint (or reuse) the browser's client token. Throws PaymentBlockedException. */
    public function token(Checkout $checkout, ?Request $request = null): string
    {
        return $this->checkouts->issueToken($checkout, $request ?? request());
    }

    /**
     * Verify the payment submitted with $request (the card-fields component
     * posts quadpayment_checkout and quadpayment_transaction). With
     * $capture = false the card stays authorized: call ->capture() once the
     * rest of your form has been handled, or ->void() if it failed.
     *
     * @throws Exceptions\PaymentVerificationException
     * @throws Exceptions\PaymentBlockedException
     */
    public function verify(?Request $request = null, bool $capture = true): PaymentResult
    {
        $request ??= request();
        $checkout = $this->checkouts->fromRequest($request);

        $transactionId = $request->input(CheckoutManager::TRANSACTION_FIELD) ?: $request->input('pl_transaction_id');

        $result = $this->checkouts->verify($checkout, is_string($transactionId) ? $transactionId : null, $capture);

        // Keep an uncaptured checkout in the session so a re-rendered form
        // reuses its authorization.
        if ($result->isCaptured() && $request->hasSession() && $checkout->purpose) {
            $request->session()->forget('quadpayment.checkouts.' . $checkout->purpose);
        }

        return $result;
    }

    /** Verify by checkout + transaction id, e.g. from an API/JSON flow. */
    public function verifyCheckout(Checkout $checkout, string $transactionId, bool $capture = true): PaymentResult
    {
        return $this->checkouts->verify($checkout, $transactionId, $capture);
    }

    /**
     * Charge a saved card server-side:
     *   QuadPayment::chargeSavedCard($user, $pmId, fn ($c) => $c->amount(20)->description('Renewal'));
     */
    public function chargeSavedCard(Model $owner, string $paymentMethodId, callable $define, string $purpose = 'saved_card', ?Request $request = null): PaymentResult
    {
        $builder = $this->checkout($purpose);
        $define($builder);

        return $this->checkouts->chargeSavedCard($owner, $paymentMethodId, $builder, $request ?? request());
    }

    public function customers(): CustomerManager
    {
        return $this->customers;
    }

    public function guard(): Guard
    {
        return $this->guard;
    }

    public function breaker(): CircuitBreaker
    {
        return $this->guard->breaker();
    }

    /** Is card processing currently available? Use to hide payment UI. */
    public function available(): bool
    {
        return ! $this->guard->enabled() || ! $this->guard->breaker()->isTripped();
    }
}
