<?php

namespace QuadCompanies\QuadPayment\Events;

use QuadCompanies\QuadPayment\Models\Attempt;

class PaymentDeclined
{
    public function __construct(public readonly Attempt $attempt) {}
}
