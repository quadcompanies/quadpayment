<?php

namespace QuadCompanies\QuadPayment\Security;

use QuadCompanies\QuadPayment\Payload\Transaction;

/**
 * Weighs a decline by how strongly it suggests the payer doesn't hold the
 * card. Bad number/CVV/ZIP/expiry are what card testers generate; NSF and
 * over-limit are what real customers generate.
 */
class DeclineClassifier
{
    public function __construct(private array $config) {}

    public function weight(Transaction $transaction): float
    {
        if (! $transaction->isDeclined()) {
            return 0.0;
        }

        $code = $transaction->statusCode();

        if ($code && in_array($code, $this->config['benign'] ?? [], true)) {
            return (float) ($this->config['benign_weight'] ?? 0.25);
        }

        // Suspicious and unrecognised codes both count fully.
        return 1.0;
    }

    public function isSuspicious(?string $code): bool
    {
        return ! $code || ! in_array($code, $this->config['benign'] ?? [], true);
    }
}
