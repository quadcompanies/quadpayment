<?php

namespace QuadCompanies\QuadPayment\Security;

use Illuminate\Support\Facades\Log;
use QuadCompanies\QuadPayment\Models\SecurityEvent;

class SecurityLog
{
    public function record(string $type, string $severity = 'info', array $context = [], ?int $checkoutId = null, ?string $ip = null): void
    {
        SecurityEvent::query()->create([
            'type' => $type,
            'severity' => $severity,
            'checkout_id' => $checkoutId,
            'ip' => $ip,
            'context' => $context ?: null,
            'created_at' => now(),
        ]);

        $level = match ($severity) {
            'critical' => 'critical',
            'warning' => 'warning',
            default => 'info',
        };

        Log::log($level, 'quadpayment: ' . $type, $context + array_filter(['checkout_id' => $checkoutId, 'ip' => $ip]));
    }
}
