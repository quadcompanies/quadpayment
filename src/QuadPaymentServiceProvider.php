<?php

namespace QuadCompanies\QuadPayment;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use QuadCompanies\QuadPayment\Checkout\CheckoutManager;
use QuadCompanies\QuadPayment\Contracts\BotVerifier;
use QuadCompanies\QuadPayment\Customers\CustomerManager;
use QuadCompanies\QuadPayment\Payload\PayloadClient;
use QuadCompanies\QuadPayment\Security\BlockList;
use QuadCompanies\QuadPayment\Security\Bot\NullVerifier;
use QuadCompanies\QuadPayment\Security\Bot\TurnstileVerifier;
use QuadCompanies\QuadPayment\Security\CircuitBreaker;
use QuadCompanies\QuadPayment\Security\DeclineClassifier;
use QuadCompanies\QuadPayment\Security\Fingerprinter;
use QuadCompanies\QuadPayment\Security\Guard;
use QuadCompanies\QuadPayment\Security\SecurityLog;
use QuadCompanies\QuadPayment\Security\Thresholds;

class QuadPaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/quadpayment.php', 'quadpayment');

        $this->app->singleton(PayloadClient::class, fn ($app) => new PayloadClient(
            $app->make(HttpFactory::class),
            (array) $app['config']['quadpayment.payload'],
        ));

        $this->app->singleton(Fingerprinter::class, fn ($app) => new Fingerprinter(
            (string) $app['config']['app.key'],
            (int) $app['config']['quadpayment.guard.ipv4_subnet'],
            (int) $app['config']['quadpayment.guard.ipv6_subnet'],
        ));

        $this->app->singleton(Thresholds::class, fn ($app) => new Thresholds((array) $app['config']['quadpayment.guard']));
        $this->app->singleton(SecurityLog::class);
        $this->app->singleton(DeclineClassifier::class, fn ($app) => new DeclineClassifier((array) $app['config']['quadpayment.decline_codes']));
        $this->app->singleton(BlockList::class);
        $this->app->singleton(CircuitBreaker::class);

        $this->app->singleton(Guard::class, fn ($app) => new Guard(
            $app->make(Thresholds::class),
            $app->make(BlockList::class),
            $app->make(CircuitBreaker::class),
            $app->make(SecurityLog::class),
            $app->make(Fingerprinter::class),
            $app->make(DeclineClassifier::class),
            $app->make(PayloadClient::class),
            (array) $app['config']['quadpayment.guard'],
        ));

        $this->app->singleton(CustomerManager::class);

        $this->app->singleton(CheckoutManager::class, fn ($app) => new CheckoutManager(
            $app->make(PayloadClient::class),
            $app->make(Guard::class),
            $app->make(Fingerprinter::class),
            $app->make(CustomerManager::class),
            $app->make(SecurityLog::class),
            (array) $app['config']['quadpayment.checkout'],
        ));

        $this->app->singleton(QuadPayment::class);

        // bindIf: an application can bind its own BotVerifier first.
        $this->app->bindIf(BotVerifier::class, fn ($app) => match ($app['config']['quadpayment.bot_verifier']) {
            'null', null, false => new NullVerifier,
            'turnstile' => new TurnstileVerifier(
                $app->make(HttpFactory::class),
                $app['config']['quadpayment.turnstile.secret'],
                $app['config']['quadpayment.turnstile.response_field'] ?? 'cf-turnstile-response',
            ),
            default => $app->make($app['config']['quadpayment.bot_verifier']),
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/quadpayment.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'quadpayment');

        // <x-quadpayment::card-fields>
        Blade::componentNamespace('QuadCompanies\\QuadPayment\\View\\Components', 'quadpayment');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/quadpayment.php' => config_path('quadpayment.php')], 'quadpayment-config');
            $this->publishes([__DIR__ . '/../resources/views' => resource_path('views/vendor/quadpayment')], 'quadpayment-views');
            $this->publishesMigrations([__DIR__ . '/../database/migrations' => database_path('migrations')], 'quadpayment-migrations');

            $this->commands([
                Console\BreakerCommand::class,
                Console\BlockCommand::class,
                Console\SetCommand::class,
                Console\WebhooksCommand::class,
                Console\PruneCommand::class,
                Console\StatusCommand::class,
            ]);
        }
    }
}
