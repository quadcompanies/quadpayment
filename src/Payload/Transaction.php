<?php

namespace QuadCompanies\QuadPayment\Payload;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * Read-only view of a Payload transaction. Handles both the v2 shape
 * (status.value, sender.account_id) and the v1 shape (status, customer_id)
 * so transactions created through either API surface verify the same way.
 */
class Transaction
{
    public function __construct(public readonly array $data) {}

    public function id(): ?string
    {
        return $this->data['id'] ?? null;
    }

    public function type(): ?string
    {
        return $this->data['type'] ?? null;
    }

    public function status(): ?string
    {
        $status = $this->data['status'] ?? null;

        return is_array($status) ? ($status['value'] ?? null) : $status;
    }

    public function statusCode(): ?string
    {
        return Arr::get($this->data, 'status.code') ?? ($this->data['status_code'] ?? null);
    }

    public function statusMessage(): ?string
    {
        return Arr::get($this->data, 'status.message') ?? ($this->data['status_message'] ?? null);
    }

    public function isSuccessful(): bool
    {
        return in_array($this->status(), ['authorized', 'processed'], true);
    }

    public function isAuthorizedOnly(): bool
    {
        return $this->status() === 'authorized';
    }

    public function isDeclined(): bool
    {
        return in_array($this->status(), ['declined', 'rejected'], true);
    }

    public function amountInCents(): ?int
    {
        $amount = $this->data['amount'] ?? null;

        return is_numeric($amount) ? (int) round(((float) $amount) * 100) : null;
    }

    public function senderAccountId(): ?string
    {
        return Arr::get($this->data, 'sender.account_id') ?? ($this->data['customer_id'] ?? null);
    }

    public function receiverAccountId(): ?string
    {
        return Arr::get($this->data, 'receiver.account_id') ?? ($this->data['processing_id'] ?? null);
    }

    public function paymentMethodId(): ?string
    {
        return Arr::get($this->data, 'sender.method_id') ?? ($this->data['payment_method_id'] ?? null);
    }

    public function orderNumber(): ?string
    {
        return $this->data['order_number'] ?? null;
    }

    public function attr(string $key): mixed
    {
        return Arr::get($this->data, 'attrs.' . $key);
    }

    public function method(): array
    {
        return Arr::get($this->data, 'sender.method') ?? ($this->data['payment_method'] ?? []);
    }

    public function isCard(): bool
    {
        $method = $this->method();

        return ($method['type'] ?? null) === 'card' || isset($method['card']);
    }

    public function cardBrand(): ?string
    {
        return Arr::get($this->method(), 'card.brand');
    }

    /** Last four digits only — Payload masks the rest. */
    public function cardLast4(): ?string
    {
        $masked = Arr::get($this->method(), 'card.card_number');

        if (! is_string($masked) || ! preg_match('/(\d{4})\s*$/', $masked, $m)) {
            return null;
        }

        return $m[1];
    }

    public function cardExpiry(): ?string
    {
        return Arr::get($this->method(), 'card.expiry');
    }

    public function initiatorIp(): ?string
    {
        return Arr::get($this->data, 'initiator.ip');
    }

    public function createdAt(): ?CarbonImmutable
    {
        $created = $this->data['created_at'] ?? null;

        try {
            return $created ? CarbonImmutable::parse($created) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
