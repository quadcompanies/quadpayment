<?php

namespace QuadCompanies\QuadPayment\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    use UsesPackageTable;

    protected string $baseTable = 'webhook_events';

    protected $guarded = [];
}
