<?php

namespace QuadCompanies\QuadPayment\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Session\ArraySessionHandler;
use Orchestra\Testbench\TestCase as Orchestra;
use QuadCompanies\QuadPayment\QuadPaymentServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected FakePayload $payload;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payload = FakePayload::install();
    }

    protected function getPackageProviders($app): array
    {
        return [QuadPaymentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('quadpayment.payload.api_key', 'secret_key_test');
        $app['config']->set('quadpayment.payload.processing_account_id', 'acct_merchant');
        $app['config']->set('quadpayment.bot_verifier', 'null');
        $app['config']->set('quadpayment.webhooks.secret', str_repeat('s', 40));
        $app['config']->set('quadpayment.guard.alert.mail_to', null);
    }

    /** A request with its own session, as if from a distinct visitor. */
    protected function visitor(string $ip = '203.0.113.10', ?Store $session = null): Request
    {
        $request = Request::create('/', 'POST', server: ['REMOTE_ADDR' => $ip]);
        $session ??= new Store('test', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);
        $this->app->instance('request', $request);

        return $request;
    }
}
