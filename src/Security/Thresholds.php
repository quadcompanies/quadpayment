<?php

namespace QuadCompanies\QuadPayment\Security;

use QuadCompanies\QuadPayment\Models\Setting;

/**
 * Guard limits: config values, overridable at runtime via the settings table
 * (`php artisan quadpayment:set declines.per_ip_per_hour 3`) so they can be
 * tightened during an attack without a deploy.
 */
class Thresholds
{
    private ?array $overrides = null;

    private int $loadedAt = 0;

    public function __construct(private array $config) {}

    public function get(string $path, mixed $default = null): mixed
    {
        $overrides = $this->overrides();

        if (array_key_exists($path, $overrides)) {
            return $overrides[$path];
        }

        return data_get($this->config, $path, $default);
    }

    public function set(string $path, mixed $value): void
    {
        Setting::write('guard.' . $path, $value);
        $this->overrides = null;
    }

    public function reset(string $path): void
    {
        Setting::forget('guard.' . $path);
        $this->overrides = null;
    }

    public function flush(): void
    {
        $this->overrides = null;
    }

    private function overrides(): array
    {
        if ($this->overrides !== null && time() - $this->loadedAt <= 30) {
            return $this->overrides;
        }

        $this->loadedAt = time();

        return $this->overrides = Setting::query()
            ->where('key', 'like', 'guard.%')
            ->get()
            ->mapWithKeys(fn (Setting $s) => [substr($s->key, 6) => $s->value['v'] ?? null])
            ->all();
    }
}
