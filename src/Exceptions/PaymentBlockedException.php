<?php

namespace QuadCompanies\QuadPayment\Exceptions;

use QuadCompanies\QuadPayment\Security\Decision;
use RuntimeException;

/** The guard refused: circuit breaker, block, rate limit or locked checkout. */
class PaymentBlockedException extends RuntimeException
{
    public function __construct(public readonly Decision $decision)
    {
        parent::__construct($decision->message ?? 'Payment blocked.');
    }

    public function reason(): ?string
    {
        return $this->decision->reason;
    }
}
