<?php

namespace QuadCompanies\QuadPayment\Console;

use Illuminate\Console\Command;
use QuadCompanies\QuadPayment\Security\Thresholds;

class SetCommand extends Command
{
    protected $signature = 'quadpayment:set {key? : e.g. declines.per_ip_per_hour} {value?} {--reset : Remove the override}';

    protected $description = 'Override a guard threshold at runtime (no deploy needed)';

    public function handle(Thresholds $thresholds): int
    {
        $key = $this->argument('key');

        if (! $key) {
            $this->line(json_encode(config('quadpayment.guard'), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if (config('quadpayment.guard.' . $key) === null) {
            $this->error("Unknown guard setting: {$key}");

            return self::INVALID;
        }

        if ($this->option('reset')) {
            $thresholds->reset($key);
            $this->info("{$key} reset to config value: " . json_encode(config('quadpayment.guard.' . $key)));

            return self::SUCCESS;
        }

        $value = $this->argument('value');

        if ($value === null) {
            $this->line("{$key} = " . json_encode($thresholds->get($key)));

            return self::SUCCESS;
        }

        $thresholds->set($key, is_numeric($value) ? $value + 0 : $value);
        $this->info("{$key} = {$value}");

        return self::SUCCESS;
    }
}
