<?php

namespace QuadCompanies\QuadPayment\Tests\Feature;

use QuadCompanies\QuadPayment\Exceptions\PaymentVerificationException;
use QuadCompanies\QuadPayment\Facades\QuadPayment;
use QuadCompanies\QuadPayment\Models\Checkout;
use QuadCompanies\QuadPayment\Tests\TestCase;

class CheckoutFlowTest extends TestCase
{
    public function test_token_is_minted_from_a_locked_down_payment_intent(): void
    {
        $request = $this->visitor();
        $checkout = QuadPayment::checkout('donation')->amount(50)->description('Donation')->email('a@example.com')->create($request);

        $token = QuadPayment::token($checkout, $request);

        $this->assertSame('client_token_2', $token);
        [$method, $path, $body, $version] = $this->payload->sent('POST', '/intents')[0];
        $this->assertSame('v2.0', $version);
        $this->assertSame('payment_form', $body['type']);
        $template = $body['payment_form']['payment_template'];
        $this->assertSame(50, (int) $template['amount']);
        $this->assertSame($checkout->reference, $template['order_number']);
        $this->assertSame('acct_1', $template['sender']['account_id']);
        $this->assertSame('acct_merchant', $template['receiver']['account_id']);
        $this->assertSame('authorized', $template['status']['value'], 'manual capture authorizes only');
        $this->assertSame(Checkout::READY, $checkout->fresh()->status);
    }

    public function test_reloading_reuses_checkout_and_token(): void
    {
        $request = $this->visitor();
        $first = QuadPayment::checkout('donation')->amount(25)->create($request);
        $token = QuadPayment::token($first, $request);

        $second = QuadPayment::checkout('donation')->amount(25)->create($request);

        $this->assertTrue($first->is($second));
        $this->assertSame($token, QuadPayment::token($second, $request));
        $this->assertCount(1, $this->payload->sent('POST', '/intents'));
    }

    public function test_changing_the_amount_starts_a_new_checkout(): void
    {
        $request = $this->visitor();
        $first = QuadPayment::checkout('donation')->amount(25)->create($request);
        $second = QuadPayment::checkout('donation')->amount(30)->create($request);

        $this->assertFalse($first->is($second));
    }

    public function test_successful_payment_is_verified_and_captured(): void
    {
        $request = $this->visitor();
        $checkout = QuadPayment::checkout()->amount(50)->metadata(['campaign' => 'spring'])->create($request);
        $txn = $this->payload->payIntent(QuadPayment::token($checkout, $request));

        $request->merge(['quadpayment_checkout' => $checkout->reference, 'quadpayment_transaction' => $txn]);
        $payment = QuadPayment::verify($request);

        $this->assertTrue($payment->isCaptured());
        $this->assertSame($txn, $payment->transactionId());
        $this->assertSame('4242', $payment->cardLast4());
        $this->assertSame(['campaign' => 'spring'], $payment->metadata());
        $this->assertSame('processed', $this->payload->transactions[$txn]['status']['value']);
        $this->assertSame(Checkout::PAID, $checkout->fresh()->status);

        // Idempotent: a double submit returns the same result, no second capture.
        $again = QuadPayment::verifyCheckout($checkout->fresh(), $txn);
        $this->assertSame($txn, $again->transactionId());
        $this->assertCount(1, $this->payload->sent('PUT', '/transactions/'));
    }

    public function test_verify_without_capture_then_void(): void
    {
        $request = $this->visitor();
        $checkout = QuadPayment::checkout()->amount(10)->create($request);
        $txn = $this->payload->payIntent(QuadPayment::token($checkout, $request));
        $request->merge(['quadpayment_checkout' => $checkout->reference, 'quadpayment_transaction' => $txn]);

        $payment = QuadPayment::verify($request, capture: false);
        $this->assertFalse($payment->isCaptured());

        // The re-rendered form reuses the authorization instead of charging again.
        $this->assertTrue($checkout->is(QuadPayment::checkout()->amount(10)->create($request)));

        $payment->void('form invalid');
        $this->assertSame('voided', $this->payload->transactions[$txn]['status']['value']);
    }

    public function test_amount_tampering_is_rejected(): void
    {
        $request = $this->visitor();
        $checkout = QuadPayment::checkout()->amount(50)->create($request);
        $txn = $this->payload->payIntent(QuadPayment::token($checkout, $request), overrides: ['amount' => 1.00]);

        $this->expectVerificationFailure('amount_mismatch', fn () => QuadPayment::verifyCheckout($checkout, $txn));
    }

    public function test_transaction_from_another_checkout_is_rejected(): void
    {
        $alice = $this->visitor('198.51.100.1');
        $aliceCheckout = QuadPayment::checkout()->amount(50)->create($alice);
        $aliceTxn = $this->payload->payIntent(QuadPayment::token($aliceCheckout, $alice));

        $mallory = $this->visitor('198.51.100.2');
        $malloryCheckout = QuadPayment::checkout()->amount(50)->create($mallory);
        QuadPayment::token($malloryCheckout, $mallory);

        $this->expectVerificationFailure('order_mismatch', fn () => QuadPayment::verifyCheckout($malloryCheckout, $aliceTxn));
    }

    public function test_transaction_cannot_be_used_twice(): void
    {
        $request = $this->visitor();
        $checkout = QuadPayment::checkout()->amount(50)->create($request);
        $txn = $this->payload->payIntent(QuadPayment::token($checkout, $request));
        QuadPayment::verifyCheckout($checkout, $txn);

        $other = QuadPayment::checkout('other')->amount(50)->create($request);
        QuadPayment::token($other, $request);

        $this->expectVerificationFailure('transaction_reused', fn () => QuadPayment::verifyCheckout($other, $txn));
    }

    public function test_checkout_is_bound_to_the_session_that_created_it(): void
    {
        $owner = $this->visitor();
        $checkout = QuadPayment::checkout()->amount(50)->create($owner);
        $txn = $this->payload->payIntent(QuadPayment::token($checkout, $owner));

        $stranger = $this->visitor();
        $stranger->merge(['quadpayment_checkout' => $checkout->reference, 'quadpayment_transaction' => $txn]);

        $this->expectVerificationFailure('session_mismatch', fn () => QuadPayment::verify($stranger));
    }

    public function test_declined_transaction_is_not_accepted(): void
    {
        $request = $this->visitor();
        $checkout = QuadPayment::checkout()->amount(50)->create($request);
        $txn = $this->payload->decline(QuadPayment::token($checkout, $request), 'insufficient_bal');

        $this->expectVerificationFailure('not_successful', fn () => QuadPayment::verifyCheckout($checkout, $txn));
    }

    public function test_saved_card_charge_requires_ownership(): void
    {
        $user = new TestUser(['id' => 7, 'email' => 'u@example.com', 'name' => 'U']);
        $request = $this->visitor();
        $customer = QuadPayment::customers()->forOwner($user);
        $this->payload->methods['pm_mine'] = ['id' => 'pm_mine', 'type' => 'card', 'status' => 'active', 'account_id' => $customer->payload_account_id, 'card' => ['brand' => 'visa', 'card_number' => 'xxxx1111', 'expiry' => '01/31']];
        $this->payload->methods['pm_theirs'] = ['id' => 'pm_theirs', 'type' => 'card', 'status' => 'active', 'account_id' => 'acct_someone_else'];

        $cards = QuadPayment::customers()->paymentMethods($user);
        $this->assertSame([['id' => 'pm_mine', 'brand' => 'visa', 'last4' => '1111', 'expiry' => '01/31', 'holder' => null]], $cards->all());

        $payment = QuadPayment::chargeSavedCard($user, 'pm_mine', fn ($c) => $c->amount(20)->description('Renewal'), request: $request);
        $this->assertTrue($payment->isCaptured());

        $this->expectVerificationFailure('method_not_owned', fn () => QuadPayment::chargeSavedCard($user, 'pm_theirs', fn ($c) => $c->amount(20), request: $request));
    }

    private function expectVerificationFailure(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected verification to fail with {$reason}");
        } catch (PaymentVerificationException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }
}

class TestUser extends \Illuminate\Database\Eloquent\Model
{
    protected $guarded = [];
    public $exists = true;
}
