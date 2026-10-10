<?php

namespace QuadCompanies\QuadPayment\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use QuadCompanies\QuadPayment\Facades\QuadPayment;
use QuadCompanies\QuadPayment\Models\Checkout;
use QuadCompanies\QuadPayment\Tests\TestCase;

class ComponentTest extends TestCase
{
    private function render(Checkout $checkout, string $attributes = ''): string
    {
        return Blade::render('<form><x-quadpayment::card-fields :checkout="$checkout" ' . $attributes . ' /></form>', ['checkout' => $checkout]);
    }

    public function test_card_inputs_are_never_posted_to_the_app(): void
    {
        $html = $this->render(QuadPayment::checkout()->amount(5)->create($this->visitor()));

        foreach (['card_number', 'expiry', 'cvc', 'account_holder', 'billing_address[postal_code]'] as $field) {
            $this->assertStringContainsString('pl-input="' . $field . '"', $html);
            $this->assertDoesNotMatchRegularExpression('/<[^>]*pl-input="' . preg_quote($field, '/') . '"[^>]*\bname=/', $html, "{$field} must not have a name attribute");
        }

        // The only named inputs are the checkout reference and transaction id.
        preg_match_all('/\bname="([^"]+)"/', $html, $names);
        $this->assertSame(['quadpayment_checkout', 'quadpayment_transaction'], $names[1]);
    }

    public function test_deferred_mode_mints_no_token_on_render(): void
    {
        $html = $this->render(QuadPayment::checkout()->amount(5)->create($this->visitor()));

        $this->assertStringContainsString('data-qp-reveal', $html);
        $this->assertStringNotContainsString('data-token=', $html);
        $this->assertCount(0, $this->payload->sent('POST', '/intents'));
    }

    public function test_eager_mode_embeds_token(): void
    {
        $html = $this->render(QuadPayment::checkout()->amount(5)->create($this->visitor()), 'mode="eager"');

        $this->assertStringContainsString('data-token="client_token_2"', $html);
    }

    public function test_breaker_renders_unavailable_state(): void
    {
        QuadPayment::guard()->tripBreaker('test');
        $html = $this->render(QuadPayment::checkout()->amount(5)->create($this->visitor()), 'mode="eager"');

        $this->assertStringContainsString('data-unavailable', $html);
        $this->assertStringContainsString('temporarily unavailable', $html);
        $this->assertStringNotContainsString('pl-input="card_number"', $html);
    }
}
