<?php

namespace QuadCompanies\QuadPayment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One card transaction as reported by Payload (webhook, server verification
 * or a client report that was re-fetched from the API). The guard's velocity
 * limits are computed from this table.
 */
class Attempt extends Model
{
    use UsesPackageTable;

    protected string $baseTable = 'attempts';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'weight' => 'float',
        'occurred_at' => 'datetime',
    ];

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    public function isDecline(): bool
    {
        return in_array($this->status, ['declined', 'rejected'], true);
    }
}
