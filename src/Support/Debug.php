<?php

namespace QuadCompanies\QuadPayment\Support;

use Illuminate\Support\Facades\Log;

/**
 * Verbose development logging, enabled with QUADPAYMENT_DEBUG=true: every
 * Payload API call, guard decision and checkout milestone goes to the
 * application log at debug level, prefixed "quadpayment.debug".
 *
 * Context may include customer names/emails and full API responses — meant
 * for local log files, not production log pipelines.
 */
class Debug
{
    public static function enabled(): bool
    {
        return (bool) config('quadpayment.debug', false);
    }

    public static function log(string $event, array $context = []): void
    {
        if (static::enabled()) {
            Log::debug('quadpayment.debug: ' . $event, $context);
        }
    }
}
