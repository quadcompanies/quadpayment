<?php

namespace QuadCompanies\QuadPayment\Checkout;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use QuadCompanies\QuadPayment\Models\Checkout;
use QuadCompanies\QuadPayment\Security\Fingerprinter;

/**
 * Fluent definition of a payment, fixed server-side:
 *
 *   $checkout = QuadPayment::checkout('donation')
 *       ->amount(50)
 *       ->description('Donation to Signature Gives Back')
 *       ->email($email)
 *       ->create($request);
 *
 * The checkout reference is remembered in the session per purpose, so page
 * reloads reuse the same checkout (and the same client token) instead of
 * minting new ones.
 */
class CheckoutBuilder
{
    private ?string $amount = null;
    private ?string $description = null;
    private ?string $email = null;
    private ?string $name = null;
    private ?Model $owner = null;
    private bool $saveCard = false;
    private array $metadata = [];
    private ?string $capture = null;
    private ?string $currency = null;

    public function __construct(
        private CheckoutManager $manager,
        private Fingerprinter $fingerprinter,
        private array $config,
        private string $purpose = 'default',
    ) {}

    public function amount(float|int|string $amount): static
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('Checkout amount must be numeric.');
        }

        // Normalise to exactly two decimals; Payload amounts are dollars.
        $this->amount = number_format(round((float) $amount, 2), 2, '.', '');

        return $this;
    }

    public function description(?string $description): static
    {
        $this->description = $description ? Str::limit($description, 250, '') : null;

        return $this;
    }

    /** Used for per-email velocity limits and the Payload customer record. */
    public function email(?string $email): static
    {
        $this->email = $email ? trim($email) : null;

        return $this;
    }

    public function name(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Charge under this model's Payload customer account (created on first
     * use) so its cards can be saved and reused.
     */
    public function for(Model $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    /** Keep the card on the owner's Payload account after payment. */
    public function saveCard(bool $save = true): static
    {
        $this->saveCard = $save;

        return $this;
    }

    public function metadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    /** "auto" charges immediately; "manual" authorizes and captures in verify(). */
    public function capture(string $mode): static
    {
        if (! in_array($mode, ['auto', 'manual'], true)) {
            throw new InvalidArgumentException('Capture mode must be "auto" or "manual".');
        }

        $this->capture = $mode;

        return $this;
    }

    public function currency(string $currency): static
    {
        $this->currency = strtoupper($currency);

        return $this;
    }

    public function create(?Request $request = null): Checkout
    {
        $request ??= request();

        if ($this->amount === null) {
            throw new InvalidArgumentException('Checkout amount is required.');
        }

        $min = (float) ($this->config['min_amount'] ?? 0);
        $max = (float) ($this->config['max_amount'] ?? PHP_FLOAT_MAX);

        if ((float) $this->amount < $min || (float) $this->amount > $max) {
            throw new InvalidArgumentException("Checkout amount must be between {$min} and {$max}.");
        }

        if ($this->saveCard && ! $this->owner) {
            throw new InvalidArgumentException('saveCard() requires for($owner).');
        }

        if ($existing = $this->reusable($request)) {
            return $existing;
        }

        $actor = $this->fingerprinter->actor($request, $this->email);

        $checkout = Checkout::query()->create([
            'reference' => 'qpc_' . Str::random(36),
            'purpose' => $this->purpose,
            'status' => Checkout::PENDING,
            'amount' => $this->amount,
            'currency' => $this->currency ?? ($this->config['currency'] ?? 'USD'),
            'description' => $this->description,
            'capture' => $this->capture ?? ($this->config['capture'] ?? 'manual'),
            'save_payment_method' => $this->saveCard,
            'ip' => $actor->ip,
            'subnet' => $actor->subnet,
            'session_hash' => $actor->sessionHash,
            'email_hash' => $actor->emailHash,
            'metadata' => array_filter([
                'app' => $this->metadata ?: null,
                'name' => $this->name,
                // Kept only so the Payload customer record carries the email;
                // limits use email_hash.
                'email' => $this->email,
            ]) ?: null,
            'expires_at' => now()->addMinutes((int) ($this->config['checkout_ttl_minutes'] ?? 60)),
        ]);

        if ($this->owner) {
            $this->manager->attachOwner($checkout, $this->owner, $this->name, $this->email);
        }

        if ($request->hasSession()) {
            $request->session()->put($this->sessionKey(), $checkout->reference);
        }

        return $checkout;
    }

    /** An open checkout for the same purpose, amount and payer in this session. */
    private function reusable(Request $request): ?Checkout
    {
        if (! $request->hasSession() || ! ($reference = $request->session()->get($this->sessionKey()))) {
            return null;
        }

        $checkout = Checkout::query()->where('reference', $reference)->first();

        // An authorized-but-uncaptured checkout is reused too: if your form
        // failed validation after the card went through, the re-rendered page
        // must carry that authorization, not take a second payment.
        if (! $checkout || ! ($checkout->isOpen() || $checkout->isAuthorized())
            || $checkout->amountInCents() !== (int) round(((float) $this->amount) * 100)
            || $checkout->save_payment_method !== $this->saveCard
            || $checkout->email_hash !== ($this->email ? $this->fingerprinter->emailHash($this->email) : null)
            || ($this->owner
                ? ($checkout->customer?->owner_type !== $this->owner->getMorphClass() || $checkout->customer?->owner_id != $this->owner->getKey())
                : $checkout->customer_id !== null)) {
            return null;
        }

        return $checkout;
    }

    private function sessionKey(): string
    {
        return 'quadpayment.checkouts.' . $this->purpose;
    }
}
