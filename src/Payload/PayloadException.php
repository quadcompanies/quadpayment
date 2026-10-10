<?php

namespace QuadCompanies\QuadPayment\Payload;

use RuntimeException;
use Throwable;

class PayloadException extends RuntimeException
{
    public function __construct(
        string $message,
        int $status = 0,
        public readonly ?string $errorType = null,
        ?Throwable $previous = null,
        public readonly array $details = [],
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function status(): int
    {
        return $this->getCode();
    }
}
