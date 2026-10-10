<?php

namespace QuadCompanies\QuadPayment\Models;

use Illuminate\Database\Eloquent\Model;

class Token extends Model
{
    use UsesPackageTable;

    public const UPDATED_AT = null;

    protected string $baseTable = 'tokens';

    protected $guarded = [];
}
