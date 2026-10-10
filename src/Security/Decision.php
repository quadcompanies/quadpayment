<?php

namespace QuadCompanies\QuadPayment\Security;

final class Decision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly ?string $message = null,
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(string $reason, string $message): self
    {
        return new self(false, $reason, $message);
    }

    public function denied(): bool
    {
        return ! $this->allowed;
    }

    /** Breaker trips and per-checkout locks end the visitor's checkout. */
    public function isTerminal(): bool
    {
        return in_array($this->reason, ['circuit_breaker', 'checkout_locked'], true)
            || str_starts_with((string) $this->reason, 'blocked_');
    }
}
