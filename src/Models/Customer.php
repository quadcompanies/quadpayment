<?php

namespace QuadCompanies\QuadPayment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Links one of the host application's models (a User, a Donor...) to the
 * Payload customer account that owns its saved payment methods.
 */
class Customer extends Model
{
    use UsesPackageTable;

    protected string $baseTable = 'customers';

    protected $guarded = [];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
