<?php

namespace QuadCompanies\QuadPayment\Exceptions;

use RuntimeException;

/**
 * The submitted payment could not be confirmed against Payload. The message
 * is safe to show customers; $reason is for logs.
 */
class PaymentVerificationException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message = 'We could not confirm your payment. You have not been charged; please try again.',
        public readonly ?string $transactionId = null,
    ) {
        parent::__construct($message);
    }
}
