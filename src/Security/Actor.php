<?php

namespace QuadCompanies\QuadPayment\Security;

/**
 * Who is attempting a payment, reduced to the identifiers the guard limits
 * on. Email and session are keyed hashes; nothing here identifies a card.
 */
final class Actor
{
    public function __construct(
        public readonly ?string $ip,
        public readonly ?string $subnet,
        public readonly ?string $sessionHash,
        public readonly ?string $emailHash = null,
        public readonly ?string $accountId = null,
    ) {}

    public function withEmailHash(?string $emailHash): self
    {
        return new self($this->ip, $this->subnet, $this->sessionHash, $emailHash ?? $this->emailHash, $this->accountId);
    }

    public function withAccountId(?string $accountId): self
    {
        return new self($this->ip, $this->subnet, $this->sessionHash, $this->emailHash, $accountId ?? $this->accountId);
    }
}
