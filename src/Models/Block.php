<?php

namespace QuadCompanies\QuadPayment\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Block extends Model
{
    use UsesPackageTable;

    public const IP = 'ip';
    public const SUBNET = 'subnet';
    public const EMAIL = 'email';
    public const CARD = 'card';
    public const SESSION = 'session';
    public const ACCOUNT = 'account';

    protected string $baseTable = 'blocks';

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
