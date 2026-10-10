<?php

namespace QuadCompanies\QuadPayment\Events;

class CircuitBreakerReset
{
    public function __construct(public readonly string $by) {}
}
