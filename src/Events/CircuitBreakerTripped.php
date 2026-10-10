<?php

namespace QuadCompanies\QuadPayment\Events;

/** Listen for this to page someone (Slack, SMS...). Mail is sent by default. */
class CircuitBreakerTripped
{
    public function __construct(
        public readonly string $reason,
        public readonly array $context = [],
    ) {}
}
