<?php

namespace QuadCompanies\QuadPayment\Events;

use QuadCompanies\QuadPayment\Checkout\PaymentResult;

class PaymentSucceeded
{
    public function __construct(public readonly PaymentResult $result) {}
}
