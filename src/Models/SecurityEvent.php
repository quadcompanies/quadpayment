<?php

namespace QuadCompanies\QuadPayment\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit trail: denials, blocks, breaker trips, failed
 * verifications and rejected webhooks.
 */
class SecurityEvent extends Model
{
    use UsesPackageTable;

    public const UPDATED_AT = null;

    protected string $baseTable = 'security_events';

    protected $guarded = [];

    protected $casts = [
        'context' => 'array',
    ];
}
