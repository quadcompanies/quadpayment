<?php

namespace QuadCompanies\QuadPayment\Tests\Feature;

use QuadCompanies\QuadPayment\Exceptions\PaymentBlockedException;
use QuadCompanies\QuadPayment\Facades\QuadPayment;
use QuadCompanies\QuadPayment\Models\Attempt;
use QuadCompanies\QuadPayment\Models\Block;
use QuadCompanies\QuadPayment\Models\Checkout;
use QuadCompanies\QuadPayment\Models\SecurityEvent;
use QuadCompanies\QuadPayment\Payload\PayloadClient;
use QuadCompanies\QuadPayment\Tests\TestCase;

class GuardTest extends TestCase
{
    private function record(string $txn, ?Checkout $checkout = null): void
    {
        QuadPayment::guard()->recordAttempt(app(PayloadClient::class)->getTransaction($txn), $checkout, 'webhook');
    }

    private function freshCheckout(string $ip = '203.0.113.10', ?string $email = null): array
    {
        $request = $this->visitor($ip);
        $checkout = QuadPayment::checkout()->amount(5)->email($email)->create($request);

        return [$checkout, QuadPayment::token($checkout, $request), $request];
    }

    public function test_legitimate_typo_then_success_is_allowed(): void
    {
        [$checkout, $token] = $this->freshCheckout();

        $this->record($this->payload->decline($token, 'invalid_card_code'), $checkout);
        $this->record($this->payload->decline($token, 'invalid_zip'), $checkout);
        $txn = $this->payload->payIntent($token);

        $payment = QuadPayment::verifyCheckout($checkout->fresh(), $txn);

        $this->assertTrue($payment->isCaptured());
        $this->assertSame(0, Block::query()->count());
    }

    public function test_checkout_locks_after_three_declines_and_revokes_its_token(): void
    {
        [$checkout, $token] = $this->freshCheckout();

        foreach (['invalid_card_code', 'invalid_card_code', 'invalid_zip'] as $code) {
            $this->record($this->payload->decline($token, $code), $checkout);
        }

        $this->assertSame(Checkout::LOCKED, $checkout->fresh()->status);
        $this->assertContains($checkout->fresh()->intent_id, $this->payload->revoked);

        // A 4th attempt that gets through anyway (token used directly against
        // Payload) is voided, not accepted.
        $late = $this->payload->payIntent($token);
        $this->expectException(PaymentBlockedException::class);

        try {
            QuadPayment::verifyCheckout($checkout->fresh(), $late);
        } finally {
            $this->assertSame('voided', $this->payload->transactions[$late]['status']['value']);
        }
    }

    public function test_many_different_cards_from_one_ip_blocks_it(): void
    {
        foreach (['1111', '2222', '3333', '4444'] as $last4) {
            [$checkout, $token] = $this->freshCheckout();
            $this->record($this->payload->decline($token, 'general_decline', $last4), $checkout);
        }

        $this->assertTrue(Block::query()->where('type', 'ip')->where('value', '203.0.113.10')->exists());

        $this->expectException(PaymentBlockedException::class);
        $this->freshCheckout();
    }

    public function test_benign_declines_weigh_less(): void
    {
        [$checkout, $token] = $this->freshCheckout();
        // Same card, four NSF declines: a real customer with a low balance.
        foreach (range(1, 2) as $_) {
            $this->record($this->payload->decline($token, 'insufficient_bal'), $checkout);
        }
        [$checkout, $token] = $this->freshCheckout();
        foreach (range(1, 2) as $_) {
            $this->record($this->payload->decline($token, 'insufficient_bal'), $checkout);
        }

        $this->assertSame(0, Block::query()->count());
    }

    public function test_token_issuance_is_rate_limited_per_ip(): void
    {
        config(['quadpayment.guard.tokens.per_ip_per_hour' => 3]);
        app(\QuadCompanies\QuadPayment\Security\Thresholds::class)->flush();

        foreach (range(1, 3) as $_) {
            $this->freshCheckout('192.0.2.50');
        }

        try {
            $this->freshCheckout('192.0.2.50');
            $this->fail('4th token should be refused');
        } catch (PaymentBlockedException $e) {
            $this->assertSame('token_rate_ip', $e->reason());
            $this->assertFalse($e->decision->isTerminal());
        }

        // A different visitor is unaffected.
        $this->freshCheckout('192.0.2.99');
        $this->assertTrue(true);
    }

    public function test_token_farming_after_refusals_blocks_the_ip(): void
    {
        config(['quadpayment.guard.tokens.per_ip_per_hour' => 2]);
        app(\QuadCompanies\QuadPayment\Security\Thresholds::class)->flush();

        $this->freshCheckout('192.0.2.60');
        $this->freshCheckout('192.0.2.60');

        foreach (range(1, 2) as $_) {
            try {
                $this->freshCheckout('192.0.2.60');
            } catch (PaymentBlockedException) {
            }
        }

        $this->assertTrue(Block::query()->where('type', 'ip')->where('value', '192.0.2.60')->exists());
    }

    public function test_decline_spike_trips_breaker_and_stops_everything(): void
    {
        // Distributed attack: each IP stays under its own limits.
        $attempts = 0;

        while (! QuadPayment::breaker()->isTripped() && $attempts < 20) {
            $ip = '10.' . ++$attempts . '.0.1';
            [$checkout, $token] = $this->freshCheckout($ip);
            $this->record($this->payload->decline($token, 'invalid_card_number', str_pad((string) $attempts, 4, '0', STR_PAD_LEFT), $ip), $checkout);
        }

        $this->assertTrue(QuadPayment::breaker()->isTripped());
        $this->assertLessThanOrEqual(10, $attempts, 'ratio rule should trip at the minimum sample');
        $this->assertFalse(QuadPayment::available());
        $this->assertTrue(SecurityEvent::query()->where('type', 'breaker_tripped')->exists());

        try {
            $this->freshCheckout('172.16.0.1');
            $this->fail('Breaker should refuse new tokens');
        } catch (PaymentBlockedException $e) {
            $this->assertSame('circuit_breaker', $e->reason());
        }

        QuadPayment::breaker()->reset('test');
        $this->freshCheckout('172.16.0.2');
        $this->assertTrue(QuadPayment::available());
    }

    public function test_breaker_auto_resets_after_cooldown(): void
    {
        QuadPayment::guard()->tripBreaker('test');
        $this->assertTrue(QuadPayment::breaker()->isTripped());

        $this->travel(61)->minutes();
        QuadPayment::breaker()->refresh();

        $this->assertFalse(QuadPayment::breaker()->isTripped());
    }

    public function test_blocks_escalate_on_repeat_offenses(): void
    {
        $blocks = QuadPayment::guard()->blocks();

        $first = $blocks->escalate('ip', '198.51.100.7', 'test');
        $this->assertEqualsWithDelta(60, now()->diffInMinutes($first->expires_at), 1);

        $this->travel(2)->hours();
        $second = $blocks->escalate('ip', '198.51.100.7', 'test');
        $this->assertSame(2, $second->strikes);
        $this->assertEqualsWithDelta(1440, now()->diffInMinutes($second->expires_at), 1);
    }

    public function test_same_card_from_many_ips_is_blocked(): void
    {
        foreach (range(1, 4) as $i) {
            [$checkout, $token] = $this->freshCheckout('100.64.' . $i . '.1');
            $this->record($this->payload->payIntent($token, 'authorized', null, '9999', '12/30', '100.64.' . $i . '.1'), $checkout);
        }

        $this->assertTrue(Block::query()->where('type', 'card')->exists());
    }

    public function test_testing_ip_is_never_blocked_and_never_trips_anything(): void
    {
        config(['quadpayment.guard.allow_ips' => ['198.51.100.99']]);
        config(['quadpayment.guard.tokens.per_ip_per_hour' => 1]);
        app(\QuadCompanies\QuadPayment\Security\Thresholds::class)->flush();

        // Far past every limit: suspicious declines, many distinct cards,
        // all on one checkout — a developer hammering the sandbox.
        [$checkout, $token] = $this->freshCheckout('198.51.100.99');

        foreach (range(1, 15) as $i) {
            $this->record($this->payload->decline($token, 'invalid_card_number', str_pad((string) $i, 4, '0', STR_PAD_LEFT), '198.51.100.99'), $checkout);
        }

        $this->assertSame(0, Block::query()->count());
        $this->assertFalse(QuadPayment::breaker()->isTripped());
        $this->assertTrue($checkout->fresh()->isOpen(), 'testing declines must not lock the checkout');
        $this->assertSame(15, Attempt::query()->count(), 'testing attempts are still recorded');

        // Fresh tokens keep coming even past the per-IP token limit.
        $this->freshCheckout('198.51.100.99');

        // And the testing noise doesn't count against real visitors: one
        // ordinary decline must not inherit a breaker-sized backlog.
        [$other, $otherToken] = $this->freshCheckout('203.0.113.77');
        $this->record($this->payload->decline($otherToken, 'invalid_card_code', '7777', '203.0.113.77'), $other);

        $this->assertFalse(QuadPayment::breaker()->isTripped());
        $this->assertSame(0, Block::query()->count());
    }

    public function test_runtime_threshold_override(): void
    {
        $this->artisan('quadpayment:set', ['key' => 'tokens.per_ip_per_hour', 'value' => 1])->assertSuccessful();

        $this->freshCheckout('192.0.2.77');
        $this->expectException(PaymentBlockedException::class);
        $this->freshCheckout('192.0.2.77');
    }
}
