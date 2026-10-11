<?php

namespace QuadCompanies\QuadPayment\Tests\Feature;

use QuadCompanies\QuadPayment\Facades\QuadPayment;
use QuadCompanies\QuadPayment\Models\Attempt;
use QuadCompanies\QuadPayment\Models\Checkout;
use QuadCompanies\QuadPayment\Tests\TestCase;

class WebhookTest extends TestCase
{
    private function deliver(array $body, ?string $secret = null)
    {
        $raw = json_encode($body);
        $signature = hash_hmac('sha256', $raw, $secret ?? config('quadpayment.webhooks.secret'));

        return $this->call('POST', '/quadpayment/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYLOAD_SIGNATURE' => $signature,
        ], $raw);
    }

    private function event(string $txn, string $trigger = 'decline', string $eventId = 'evt_1'): array
    {
        return ['object' => 'webhook_trigger', 'event_id' => $eventId, 'trigger' => $trigger, 'triggered_on' => ['id' => $txn, 'object' => 'transaction', 'value' => $trigger]];
    }

    public function test_unsigned_or_forged_webhooks_are_rejected(): void
    {
        $this->deliver($this->event('txn_x'), 'wrong-secret')->assertStatus(401);
        $this->postJson('/quadpayment/webhook', $this->event('txn_x'))->assertStatus(401);
        $this->assertSame(0, Attempt::query()->count());
    }

    public function test_decline_webhook_is_counted_once(): void
    {
        $request = $this->visitor();
        $checkout = QuadPayment::checkout()->amount(5)->create($request);
        $txn = $this->payload->decline(QuadPayment::token($checkout, $request));

        $this->deliver($this->event($txn))->assertOk();
        $this->deliver($this->event($txn))->assertOk()->assertJson(['duplicate' => true]);
        $this->deliver($this->event($txn, eventId: 'evt_2'))->assertOk();

        $this->assertSame(1, Attempt::query()->count());
        $this->assertSame(1, $checkout->fresh()->decline_count);
    }

    public function test_success_on_a_locked_checkout_is_voided(): void
    {
        $request = $this->visitor();
        $checkout = QuadPayment::checkout()->amount(5)->create($request);
        $token = QuadPayment::token($checkout, $request);
        $checkout->forceFill(['status' => Checkout::LOCKED])->save();

        $txn = $this->payload->payIntent($token);
        $this->deliver($this->event($txn, 'authorized'))->assertOk();

        $this->assertSame('voided', $this->payload->transactions[$txn]['status']['value']);
    }

    public function test_unknown_transaction_asks_payload_to_retry(): void
    {
        $this->deliver($this->event('txn_missing'))->assertStatus(503);
    }

    public function test_signature_verification_can_be_disabled_outside_production(): void
    {
        config(['quadpayment.webhooks.verify' => false]);

        $request = $this->visitor();
        $checkout = QuadPayment::checkout()->amount(5)->create($request);
        $txn = $this->payload->decline(QuadPayment::token($checkout, $request));

        // Unsigned delivery is accepted and fully processed.
        $this->postJson('/quadpayment/webhook', $this->event($txn))->assertOk();
        $this->assertSame(1, Attempt::query()->count());
        $this->assertSame(1, $checkout->fresh()->decline_count);
    }

    public function test_disabling_signature_verification_is_ignored_in_production(): void
    {
        config(['quadpayment.webhooks.verify' => false]);
        $this->app['env'] = 'production';

        $this->postJson('/quadpayment/webhook', $this->event('txn_x'))->assertStatus(401);
        $this->assertSame(0, Attempt::query()->count());
    }
}
