<?php

namespace QuadCompanies\QuadPayment\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \QuadCompanies\QuadPayment\Checkout\CheckoutBuilder checkout(string $purpose = 'default')
 * @method static string token(\QuadCompanies\QuadPayment\Models\Checkout $checkout, ?\Illuminate\Http\Request $request = null)
 * @method static \QuadCompanies\QuadPayment\Checkout\PaymentResult verify(?\Illuminate\Http\Request $request = null, bool $capture = true)
 * @method static \QuadCompanies\QuadPayment\Checkout\PaymentResult verifyCheckout(\QuadCompanies\QuadPayment\Models\Checkout $checkout, string $transactionId, bool $capture = true)
 * @method static \QuadCompanies\QuadPayment\Checkout\PaymentResult chargeSavedCard(\Illuminate\Database\Eloquent\Model $owner, string $paymentMethodId, callable $define, string $purpose = 'saved_card', ?\Illuminate\Http\Request $request = null)
 * @method static \QuadCompanies\QuadPayment\Customers\CustomerManager customers()
 * @method static \QuadCompanies\QuadPayment\Security\Guard guard()
 * @method static \QuadCompanies\QuadPayment\Security\CircuitBreaker breaker()
 * @method static bool available()
 *
 * @see \QuadCompanies\QuadPayment\QuadPayment
 */
class QuadPayment extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \QuadCompanies\QuadPayment\QuadPayment::class;
    }
}
