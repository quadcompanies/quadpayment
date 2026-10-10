<?php

namespace QuadCompanies\QuadPayment\Checkout;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use QuadCompanies\QuadPayment\Customers\CustomerManager;
use QuadCompanies\QuadPayment\Events\PaymentSucceeded;
use QuadCompanies\QuadPayment\Exceptions\PaymentBlockedException;
use QuadCompanies\QuadPayment\Exceptions\PaymentVerificationException;
use QuadCompanies\QuadPayment\Models\Checkout;
use QuadCompanies\QuadPayment\Payload\PayloadClient;
use QuadCompanies\QuadPayment\Payload\PayloadException;
use QuadCompanies\QuadPayment\Payload\Transaction;
use QuadCompanies\QuadPayment\Security\Actor;
use QuadCompanies\QuadPayment\Security\Decision;
use QuadCompanies\QuadPayment\Security\Fingerprinter;
use QuadCompanies\QuadPayment\Security\Guard;
use QuadCompanies\QuadPayment\Security\SecurityLog;

class CheckoutManager
{
    public const CHECKOUT_FIELD = 'quadpayment_checkout';
    public const TRANSACTION_FIELD = 'quadpayment_transaction';

    public function __construct(
        private PayloadClient $payload,
        private Guard $guard,
        private Fingerprinter $fingerprinter,
        private CustomerManager $customers,
        private SecurityLog $log,
        private array $config,
    ) {}

    // ---- Tokens ------------------------------------------------------------

    /**
     * Mint (or reuse) the client token the browser needs to render Payload's
     * secure card fields. Guarded: breaker, blocks and token velocity limits.
     *
     * @throws PaymentBlockedException
     */
    public function issueToken(Checkout $checkout, Request $request): string
    {
        return $this->locked($checkout, function () use ($checkout, $request) {
            $checkout->refresh();
            $actor = $this->actorFor($checkout, $request);

            $decision = $this->guard->check($actor, $checkout);

            if ($decision->denied()) {
                throw new PaymentBlockedException($decision);
            }

            if (! $checkout->isOpen()) {
                throw new PaymentBlockedException(Decision::deny(
                    'checkout_closed',
                    'This payment session has ended. Please start over.'
                ));
            }

            if ($checkout->hasUsableToken()) {
                return $checkout->client_token;
            }

            $decision = $this->guard->checkTokenIssuance($actor, $checkout);

            if ($decision->denied()) {
                throw new PaymentBlockedException($decision);
            }

            if (! $checkout->payload_account_id) {
                // A dedicated Payload customer per anonymous checkout ties
                // every transaction made with this token back to it.
                $account = $this->payload->createCustomerAccount(
                    $checkout->metadata['name'] ?? null,
                    $checkout->metadata['email'] ?? null,
                );
                $checkout->forceFill(['payload_account_id' => $account['id']])->save();
            }

            $intent = $this->payload->createPaymentFormIntent($this->paymentTemplate($checkout));

            if (empty($intent['token'])) {
                throw new PayloadException('Payload did not return a client token for the payment intent.');
            }

            $checkout->forceFill([
                'status' => Checkout::READY,
                'intent_id' => $intent['id'] ?? null,
                'client_token' => $intent['token'],
                'token_expires_at' => now()->addMinutes((int) ($this->config['token_ttl_minutes'] ?? 10)),
                'tokens_issued' => $checkout->tokens_issued + 1,
            ])->save();

            $this->guard->recordTokenIssued($actor, $checkout, $intent['id'] ?? null);

            return $intent['token'];
        });
    }

    private function paymentTemplate(Checkout $checkout): array
    {
        $template = [
            'amount' => (float) $checkout->amount,
            'description' => $checkout->description,
            // Unique per checkout: binds the transaction to this checkout and
            // gives Payload's per-order duplicate protection.
            'order_number' => $checkout->reference,
            'sender' => ['account_id' => $checkout->payload_account_id],
            'attrs' => ['quadpayment_checkout' => $checkout->reference],
        ];

        if ($receiver = $this->payload->processingAccountId()) {
            $template['receiver'] = ['account_id' => $receiver];
        }

        if ($checkout->capture === 'manual') {
            $template['status'] = ['value' => 'authorized'];
        }

        return array_filter($template, fn ($v) => $v !== null);
    }

    // ---- Verification ------------------------------------------------------

    /**
     * Resolve the checkout a form submission refers to. It must belong to the
     * current visitor's session.
     */
    public function fromRequest(Request $request): Checkout
    {
        $reference = $request->input(self::CHECKOUT_FIELD);

        $checkout = is_string($reference)
            ? Checkout::query()->where('reference', $reference)->first()
            : null;

        if (! $checkout) {
            throw new PaymentVerificationException('checkout_missing', 'Your payment session has expired. Please try again.');
        }

        if ($checkout->session_hash && $checkout->session_hash !== $this->fingerprinter->sessionHash($request)) {
            $this->log->record('session_mismatch', 'warning', [], $checkout->id, $request->ip());

            throw new PaymentVerificationException('session_mismatch', 'Your payment session has expired. Please try again.');
        }

        return $checkout;
    }

    /**
     * Confirm with Payload that the transaction the browser reported is a
     * successful payment for exactly this checkout, then (optionally)
     * capture it. Safe to call twice: a checkout that's already paid returns
     * its result.
     *
     * @throws PaymentVerificationException|PaymentBlockedException
     */
    public function verify(Checkout $checkout, ?string $transactionId, bool $capture = true): PaymentResult
    {
        // Serialised per checkout, but not inside a DB transaction: the audit
        // trail, attempt records and blocks written below must survive the
        // exceptions that reject a payment.
        $result = $this->locked($checkout, fn () => $this->authorize($checkout->refresh(), $transactionId));

        if ($capture && ! $result->isCaptured()) {
            $result->capture();
        }

        return $result;
    }

    private function authorize(Checkout $checkout, ?string $transactionId): PaymentResult
    {
        if ($checkout->isPaid() || $checkout->isAuthorized()) {
            if ($transactionId && $transactionId !== $checkout->transaction_id) {
                throw new PaymentVerificationException('already_completed', 'This payment has already been completed.', $transactionId);
            }

            return new PaymentResult($checkout, $this->payload->getTransaction($checkout->transaction_id), $this);
        }

        if (! $transactionId || ! preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $transactionId)) {
            throw new PaymentVerificationException('transaction_missing');
        }

        if (Checkout::query()->where('transaction_id', $transactionId)->exists()) {
            $this->fail($checkout, 'transaction_reused', $transactionId);
        }

        try {
            $transaction = $this->payload->getTransaction($transactionId);
        } catch (PayloadException $e) {
            $this->fail($checkout, 'transaction_lookup_failed', $transactionId, ['error' => $e->getMessage()]);
        }

        $this->assertBelongsTo($checkout, $transaction);

        // Count it (idempotent with the webhook) before deciding anything.
        $this->guard->recordAttempt($transaction, $checkout, 'server');
        $checkout->refresh();

        if (! $transaction->isSuccessful()) {
            $this->fail($checkout, 'not_successful', $transactionId, [
                'status' => $transaction->status(),
                'code' => $transaction->statusCode(),
            ], $transaction->statusMessage() ?: 'Your card was declined. Please try another card.');
        }

        $this->rejectIfBlocked($checkout, $transaction);

        $checkout->forceFill([
            'status' => Checkout::AUTHORIZED,
            'transaction_id' => $transaction->id(),
            'payment_method_id' => $transaction->paymentMethodId(),
            'card_brand' => $transaction->cardBrand(),
            'card_last4' => $transaction->cardLast4(),
            'client_token' => null,
            'authorized_at' => now(),
        ])->save();

        return new PaymentResult($checkout, $transaction, $this);
    }

    /**
     * A success that arrives on a locked checkout, with a blocked card or
     * actor, or while the breaker is tripped is released rather than kept —
     * enforcement at capture time covers attempts made directly against
     * Payload with a token we could not revoke in time.
     */
    private function rejectIfBlocked(Checkout $checkout, Transaction $transaction): void
    {
        $ip = $transaction->initiatorIp() ?: $checkout->ip;
        $actor = new Actor($ip, $this->fingerprinter->subnet($ip), $checkout->session_hash, $checkout->email_hash, $checkout->payload_account_id);

        $decision = in_array($checkout->status, [Checkout::LOCKED, Checkout::VOIDED, Checkout::EXPIRED], true)
            ? Decision::deny('checkout_locked', config('quadpayment.guard.messages.checkout_locked', 'Please start over.'))
            : $this->guard->check($actor, null, $this->guard->cardHashFor($transaction));

        if ($decision->allowed) {
            return;
        }

        $this->safeVoid($transaction);
        $checkout->forceFill(['status' => $checkout->status === Checkout::LOCKED ? Checkout::LOCKED : Checkout::VOIDED])->save();
        $this->log->record('voided_on_verify', 'warning', ['reason' => $decision->reason, 'transaction_id' => $transaction->id()], $checkout->id, $ip);

        throw new PaymentBlockedException($decision);
    }

    /** Every check that ties a Payload transaction to this checkout. */
    private function assertBelongsTo(Checkout $checkout, Transaction $transaction): void
    {
        $id = $transaction->id();

        if ($transaction->type() && $transaction->type() !== 'payment') {
            $this->fail($checkout, 'wrong_type', $id, ['type' => $transaction->type()]);
        }

        $matchesOrder = $transaction->orderNumber() === $checkout->reference
            || $transaction->attr('quadpayment_checkout') === $checkout->reference;

        if (! $matchesOrder) {
            $this->fail($checkout, 'order_mismatch', $id, ['order_number' => $transaction->orderNumber()]);
        }

        if (! $checkout->payload_account_id || $transaction->senderAccountId() !== $checkout->payload_account_id) {
            $this->fail($checkout, 'account_mismatch', $id, [
                'expected' => $checkout->payload_account_id,
                'actual' => $transaction->senderAccountId(),
            ]);
        }

        $receiver = $this->payload->processingAccountId();

        if ($receiver && $transaction->receiverAccountId() && $transaction->receiverAccountId() !== $receiver) {
            $this->fail($checkout, 'receiver_mismatch', $id, ['actual' => $transaction->receiverAccountId()]);
        }

        if ($transaction->amountInCents() !== $checkout->amountInCents()) {
            $this->fail($checkout, 'amount_mismatch', $id, [
                'expected' => $checkout->amountInCents(),
                'actual' => $transaction->amountInCents(),
            ]);
        }
    }

    public function capture(PaymentResult $result): Transaction
    {
        $checkout = $result->checkout();

        return $this->locked($checkout, function () use ($checkout, $result) {
            $checkout->refresh();

            if ($checkout->isPaid()) {
                return $result->transaction();
            }

            if (! $checkout->isAuthorized()) {
                throw new PaymentVerificationException('not_capturable', 'This payment can no longer be completed.', $result->transactionId());
            }

            $transaction = $result->transaction();

            if ($transaction->isAuthorizedOnly()) {
                try {
                    $transaction = $this->payload->captureTransaction($transaction->id());
                } catch (PayloadException $e) {
                    $this->log->record('capture_failed', 'warning', ['error' => $e->getMessage(), 'transaction_id' => $transaction->id()], $checkout->id);

                    throw new PaymentVerificationException('capture_failed', 'We could not complete your payment and you have not been charged. Please try again.', $transaction->id());
                }
            }

            $checkout->forceFill(['status' => Checkout::PAID, 'completed_at' => now()])->save();

            $this->customers->settlePaymentMethod($checkout);

            event(new PaymentSucceeded($result));

            return $transaction;
        });
    }

    /**
     * Release an authorization (or void an unsettled capture) — for when the
     * rest of your form fails after the card went through.
     */
    public function void(PaymentResult $result, string $reason = 'voided by application'): void
    {
        $checkout = $result->checkout();

        $this->safeVoid($result->transaction());
        $checkout->forceFill(['status' => Checkout::VOIDED])->save();
        $this->log->record('voided', 'info', ['reason' => $reason, 'transaction_id' => $result->transactionId()], $checkout->id);
    }

    // ---- Saved cards -------------------------------------------------------

    /**
     * Charge one of the owner's saved cards server-side. The guard still
     * applies (breaker, blocks, distinct-card limits).
     *
     * @throws PaymentVerificationException|PaymentBlockedException
     */
    public function chargeSavedCard(Model $owner, string $paymentMethodId, CheckoutBuilder $builder, Request $request): PaymentResult
    {
        $customer = $this->customers->find($owner);

        if (! $customer || ! $this->customers->ownsPaymentMethod($customer, $paymentMethodId)) {
            throw new PaymentVerificationException('method_not_owned', 'That saved card is not available.');
        }

        $checkout = $builder->for($owner)->capture('auto')->create($request);
        $actor = $this->actorFor($checkout, $request);
        $decision = $this->guard->check($actor, $checkout);

        if ($decision->denied()) {
            throw new PaymentBlockedException($decision);
        }

        $attributes = [
            'amount' => (float) $checkout->amount,
            'description' => $checkout->description,
            'order_number' => $checkout->reference,
            'sender' => ['account_id' => $customer->payload_account_id, 'method_id' => $paymentMethodId],
            'attrs' => ['quadpayment_checkout' => $checkout->reference],
        ];

        if ($receiver = $this->payload->processingAccountId()) {
            $attributes['receiver'] = ['account_id' => $receiver];
        }

        try {
            $created = $this->payload->createPayment(array_filter($attributes));
        } catch (PayloadException $e) {
            $this->log->record('saved_card_charge_failed', 'warning', ['error' => $e->getMessage()], $checkout->id, $request->ip());

            throw new PaymentVerificationException('charge_failed', 'Your saved card could not be charged. Please try another card.');
        }

        return $this->verify($checkout, $created->id());
    }

    // ---- Helpers -----------------------------------------------------------

    public function attachOwner(Checkout $checkout, Model $owner, ?string $name, ?string $email): void
    {
        $customer = $this->customers->forOwner($owner, $name, $email);

        $checkout->forceFill([
            'customer_id' => $customer->id,
            'payload_account_id' => $customer->payload_account_id,
        ])->save();
    }

    public function actorFor(Checkout $checkout, Request $request): Actor
    {
        return $this->fingerprinter->actor($request)
            ->withEmailHash($checkout->email_hash)
            ->withAccountId($checkout->customer_id ? $checkout->payload_account_id : null);
    }

    /** Find the checkout a Payload transaction belongs to (webhooks). */
    public function findForTransaction(Transaction $transaction): ?Checkout
    {
        $references = array_filter([$transaction->orderNumber(), $transaction->attr('quadpayment_checkout')], 'is_string');

        if ($references) {
            $checkout = Checkout::query()->whereIn('reference', $references)->first();

            if ($checkout) {
                return $checkout;
            }
        }

        if ($account = $transaction->senderAccountId()) {
            return Checkout::query()
                ->where('payload_account_id', $account)
                ->whereNull('customer_id')
                ->latest('id')
                ->first();
        }

        return null;
    }

    /** Run $callback while holding an atomic lock on the checkout. */
    private function locked(Checkout $checkout, callable $callback): mixed
    {
        return Cache::lock('quadpayment:checkout:' . $checkout->getKey(), 30)->block(20, $callback);
    }

    public function safeVoid(Transaction $transaction): void
    {
        if (! $transaction->id() || ! $transaction->isSuccessful()) {
            return;
        }

        try {
            $this->payload->voidTransaction($transaction->id());
        } catch (PayloadException $e) {
            $this->log->record('void_failed', 'critical', ['transaction_id' => $transaction->id(), 'error' => $e->getMessage()]);
        }
    }

    /** @return never */
    private function fail(Checkout $checkout, string $reason, ?string $transactionId, array $context = [], ?string $message = null): void
    {
        $this->log->record('verification_failed', 'warning', ['reason' => $reason, 'transaction_id' => $transactionId] + $context, $checkout->id, $checkout->ip);

        throw $message
            ? new PaymentVerificationException($reason, $message, $transactionId)
            : new PaymentVerificationException($reason, transactionId: $transactionId);
    }
}
