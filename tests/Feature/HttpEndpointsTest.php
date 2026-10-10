<?php

namespace QuadCompanies\QuadPayment\Tests\Feature;

use Illuminate\Http\Request;
use QuadCompanies\QuadPayment\Contracts\BotVerifier;
use QuadCompanies\QuadPayment\Facades\QuadPayment;
use QuadCompanies\QuadPayment\Tests\TestCase;

class HttpEndpointsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Test the real routes without CSRF noise.
        $app['config']->set('quadpayment.checkout.middleware', ['web']);
    }

    private function checkoutViaWeb(): string
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        \Illuminate\Support\Facades\Route::middleware('web')->get('/_checkout', fn () => QuadPayment::checkout()->amount(15)->create()->reference);

        return $this->get('/_checkout')->getContent();
    }

    public function test_token_endpoint_mints_token(): void
    {
        $reference = $this->checkoutViaWeb();

        $this->postJson('/quadpayment/token', ['quadpayment_checkout' => $reference])
            ->assertOk()
            ->assertJsonStructure(['token'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_failed_bot_check_mints_nothing(): void
    {
        $this->app->bind(BotVerifier::class, fn () => new class implements BotVerifier {
            public function verify(Request $request): bool { return false; }
            public function responseField(): ?string { return 'cf-turnstile-response'; }
        });

        $reference = $this->checkoutViaWeb();

        $this->postJson('/quadpayment/token', ['quadpayment_checkout' => $reference])->assertStatus(422);
        $this->assertCount(0, $this->payload->sent('POST', '/intents'));
    }

    public function test_unknown_checkout_is_refused(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->postJson('/quadpayment/token', ['quadpayment_checkout' => 'qpc_nope'])->assertStatus(410);
    }

    public function test_declined_report_returns_remaining_attempts(): void
    {
        $reference = $this->checkoutViaWeb();
        $token = $this->postJson('/quadpayment/token', ['quadpayment_checkout' => $reference])->json('token');
        $txn = $this->payload->decline($token);

        $this->postJson('/quadpayment/declined', ['quadpayment_checkout' => $reference, 'transaction_id' => $txn])
            ->assertOk()
            ->assertJson(['locked' => false, 'remaining' => 2]);
    }

    public function test_script_is_served(): void
    {
        $this->get('/quadpayment/quadpayment.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
    }
}
