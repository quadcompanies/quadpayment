<?php

namespace QuadCompanies\QuadPayment\Tests\Feature;

use QuadCompanies\QuadPayment\Facades\QuadPayment;
use QuadCompanies\QuadPayment\Models\Block;
use QuadCompanies\QuadPayment\Models\Checkout;
use QuadCompanies\QuadPayment\Payload\PayloadClient;
use QuadCompanies\QuadPayment\Tests\TestCase;

class OperationsTest extends TestCase
{
    public function test_one_unlucky_customer_on_a_quiet_site_is_not_treated_as_an_attack(): void
    {
        $record = fn ($txn, $checkout) => QuadPayment::guard()->recordAttempt(app(PayloadClient::class)->getTransaction($txn), $checkout, 'webhook');
        $request = $this->visitor('198.51.100.20');

        // Three declines lock the first checkout...
        $first = QuadPayment::checkout()->amount(25)->create($request);
        $token = QuadPayment::token($first, $request);
        foreach (['invalid_card_code', 'invalid_zip', 'insufficient_bal'] as $code) {
            $record($this->payload->decline($token, $code, '4242', '198.51.100.20'), $first);
        }
        $this->assertSame(Checkout::LOCKED, $first->fresh()->status);

        // ...they start over with a second card and it works.
        $second = QuadPayment::checkout()->amount(25)->create($request);
        $this->assertFalse($first->is($second));
        $token = QuadPayment::token($second, $request);
        $record($this->payload->decline($token, 'invalid_card_code', '5555', '198.51.100.20'), $second);
        $txn = $this->payload->payIntent($token, last4: '5555', ip: '198.51.100.20');

        $this->assertTrue(QuadPayment::verifyCheckout($second->fresh(), $txn)->isCaptured());
        $this->assertFalse(QuadPayment::breaker()->isTripped());
        $this->assertSame(0, Block::query()->count());
    }

    public function test_prune_voids_abandoned_authorizations(): void
    {
        $request = $this->visitor();
        $checkout = QuadPayment::checkout()->amount(10)->create($request);
        $txn = $this->payload->payIntent(QuadPayment::token($checkout, $request));
        QuadPayment::verifyCheckout($checkout, $txn, capture: false);

        $this->travel(61)->minutes();
        $this->artisan('quadpayment:prune')->assertSuccessful();

        $this->assertSame(Checkout::VOIDED, $checkout->fresh()->status);
        $this->assertSame('voided', $this->payload->transactions[$txn]['status']['value']);
    }

    public function test_breaker_and_block_commands(): void
    {
        $this->artisan('quadpayment:breaker', ['action' => 'trip', '--reason' => 'drill'])->assertSuccessful();
        $this->assertTrue(QuadPayment::breaker()->isTripped());
        $this->artisan('quadpayment:breaker')->expectsOutputToContain('TRIPPED')->assertSuccessful();
        $this->artisan('quadpayment:breaker', ['action' => 'reset'])->assertSuccessful();
        $this->assertFalse(QuadPayment::breaker()->isTripped());

        $this->artisan('quadpayment:block', ['action' => 'add', 'type' => 'email', 'value' => 'Bad+1@Example.com'])->assertSuccessful();
        $this->assertTrue(QuadPayment::guard()->blocks()->isBlocked('email', app(\QuadCompanies\QuadPayment\Security\Fingerprinter::class)->emailHash('bad@example.com')));
        $this->artisan('quadpayment:block', ['action' => 'remove', 'type' => 'email', 'value' => 'bad@example.com'])->assertSuccessful();
        $this->assertSame(0, Block::query()->count());

        $this->artisan('quadpayment:status')->assertSuccessful();
    }

    public function test_webhook_registration_signs_every_trigger_once(): void
    {
        $this->artisan('quadpayment:webhooks')->assertSuccessful();
        $this->artisan('quadpayment:webhooks')->assertSuccessful();

        $created = $this->payload->sent('POST', '/webhooks');
        $this->assertCount(count(config('quadpayment.webhooks.triggers')), $created);
        $this->assertContains('decline', array_column(array_column($created, 2), 'trigger'));
        $this->assertSame(config('quadpayment.webhooks.secret'), $created[0][2]['sender_secret']);
        $this->assertStringEndsWith('/quadpayment/webhook', $created[0][2]['url']);
    }
}
