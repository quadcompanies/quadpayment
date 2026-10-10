<?php

use Illuminate\Support\Facades\Route;
use QuadCompanies\QuadPayment\Http\Controllers\CheckoutController;
use QuadCompanies\QuadPayment\Http\Controllers\WebhookController;

$prefix = config('quadpayment.checkout.route_prefix', 'quadpayment');

// Browser endpoints: session + CSRF, plus a coarse per-IP throttle in front
// of the guard's own limits.
Route::middleware(array_merge((array) config('quadpayment.checkout.middleware', ['web']), ['throttle:30,1']))
    ->prefix($prefix)
    ->name('quadpayment.')
    ->group(function () {
        Route::post('token', [CheckoutController::class, 'token'])->name('token');
        Route::post('declined', [CheckoutController::class, 'declined'])->name('declined');
    });

Route::get($prefix . '/quadpayment.js', [CheckoutController::class, 'script'])->name('quadpayment.script');

// Server-to-server: no session or CSRF; authenticated by HMAC signature.
Route::post(config('quadpayment.webhooks.path', 'quadpayment/webhook'), WebhookController::class)
    ->middleware('throttle:300,1')
    ->name('quadpayment.webhook');
