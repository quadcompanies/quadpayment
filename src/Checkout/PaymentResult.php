<?php

namespace QuadCompanies\QuadPayment\Checkout;

use QuadCompanies\QuadPayment\Models\Checkout;
use QuadCompanies\QuadPayment\Payload\Transaction;

/**
 * A payment verified against Payload. Store transactionId() alongside your
 * own records (order, donation...) — it's the key for refunds and support.
 */
class PaymentResult
{
    public function __construct(
        private Checkout $checkout,
        private Transaction $transaction,
        private CheckoutManager $manager,
    ) {}

    public function checkout(): Checkout
    {
        return $this->checkout;
    }

    public function transaction(): Transaction
    {
        return $this->transaction;
    }

    public function transactionId(): ?string
    {
        return $this->checkout->transaction_id ?? $this->transaction->id();
    }

    public function amount(): string
    {
        return (string) $this->checkout->amount;
    }

    public function currency(): string
    {
        return $this->checkout->currency;
    }

    public function cardBrand(): ?string
    {
        return $this->checkout->card_brand;
    }

    public function cardLast4(): ?string
    {
        return $this->checkout->card_last4;
    }

    public function paymentMethodId(): ?string
    {
        return $this->checkout->payment_method_id;
    }

    /** The metadata passed to the checkout builder. */
    public function metadata(): array
    {
        return $this->checkout->metadata['app'] ?? [];
    }

    public function isCaptured(): bool
    {
        return $this->checkout->isPaid();
    }

    /** Capture an authorization obtained with verify(capture: false). */
    public function capture(): static
    {
        $this->transaction = $this->manager->capture($this);

        return $this;
    }

    /** Release the authorization — e.g. when your own form handling fails. */
    public function void(string $reason = 'voided by application'): void
    {
        $this->manager->void($this, $reason);
    }
}
