<?php

namespace QuadCompanies\QuadPayment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Server-side state for one payment. The amount, description and the Payload
 * account the card is charged under are fixed here when the checkout is
 * created; the browser can't change them, and verification compares the
 * Payload transaction against this row — never against request input.
 *
 * Lifecycle: pending → ready (client token minted) → authorized → paid
 *            ↘ locked (too many declines) / expired / voided
 */
class Checkout extends Model
{
    use UsesPackageTable;

    public const PENDING = 'pending';
    public const READY = 'ready';
    public const AUTHORIZED = 'authorized';
    public const PAID = 'paid';
    public const LOCKED = 'locked';
    public const EXPIRED = 'expired';
    public const VOIDED = 'voided';

    protected string $baseTable = 'checkouts';

    protected $guarded = [];

    protected $hidden = ['client_token'];

    protected $casts = [
        'amount' => 'decimal:2',
        'decline_score' => 'float',
        'save_payment_method' => 'boolean',
        'client_token' => 'encrypted',
        'metadata' => 'array',
        'token_expires_at' => 'datetime',
        'authorized_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class);
    }

    /** Can the customer still try a card on this checkout? */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::PENDING, self::READY], true)
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    public function isAuthorized(): bool
    {
        return $this->status === self::AUTHORIZED;
    }

    public function hasUsableToken(): bool
    {
        return $this->status === self::READY
            && $this->client_token
            && $this->token_expires_at?->isFuture();
    }

    public function amountInCents(): int
    {
        return (int) round(((float) $this->amount) * 100);
    }
}
