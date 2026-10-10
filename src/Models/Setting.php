<?php

namespace QuadCompanies\QuadPayment\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Runtime state and overrides: the circuit breaker, and any guard threshold
 * changed with `quadpayment:set` without a deploy.
 */
class Setting extends Model
{
    use UsesPackageTable;

    public const CREATED_AT = null;

    protected string $baseTable = 'settings';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'value' => 'array',
    ];

    public static function read(string $key, mixed $default = null): mixed
    {
        return static::query()->find($key)?->value['v'] ?? $default;
    }

    public static function write(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
    }

    public static function forget(string $key): void
    {
        static::query()->whereKey($key)->delete();
    }
}
