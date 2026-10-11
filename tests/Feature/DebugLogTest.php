<?php

namespace QuadCompanies\QuadPayment\Tests\Feature;

use Illuminate\Support\Facades\Log;
use QuadCompanies\QuadPayment\Facades\QuadPayment;
use QuadCompanies\QuadPayment\Tests\TestCase;

class DebugLogTest extends TestCase
{
    private function completeAPayment(): void
    {
        $request = $this->visitor();
        $checkout = QuadPayment::checkout()->amount(5)->create($request);
        $token = QuadPayment::token($checkout, $request);
        QuadPayment::verifyCheckout($checkout->fresh(), $this->payload->payIntent($token));
    }

    public function test_debug_mode_logs_api_calls_decisions_and_milestones(): void
    {
        config(['quadpayment.debug' => true]);
        Log::spy();

        $this->completeAPayment();

        foreach (['payload.post', 'payload.get', 'guard.check', 'guard.token_issuance', 'guard.attempt_recorded', 'checkout.token_minted', 'checkout.authorized', 'checkout.captured'] as $expected) {
            Log::shouldHaveReceived('debug')
                ->withArgs(fn ($message) => $message === 'quadpayment.debug: ' . $expected)
                ->atLeast()->once();
        }
    }

    public function test_debug_logging_is_off_by_default(): void
    {
        Log::spy();

        $this->completeAPayment();

        Log::shouldNotHaveReceived('debug');
    }
}
