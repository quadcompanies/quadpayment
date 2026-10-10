<?php

namespace QuadCompanies\QuadPayment\Customers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use QuadCompanies\QuadPayment\Models\Checkout;
use QuadCompanies\QuadPayment\Models\Customer;
use QuadCompanies\QuadPayment\Payload\PayloadClient;
use QuadCompanies\QuadPayment\Payload\PayloadException;
use QuadCompanies\QuadPayment\Security\Fingerprinter;
use QuadCompanies\QuadPayment\Security\SecurityLog;

/**
 * Payload customer accounts for your models, and their saved cards.
 *
 * Saved cards live only in Payload. Listing them asks Payload each time and
 * returns brand / last four / expiry / payment method id — enough to render
 * "Visa ending 4242" and charge it by id, never enough to reconstruct a card.
 */
class CustomerManager
{
    public function __construct(
        private PayloadClient $payload,
        private Fingerprinter $fingerprinter,
        private SecurityLog $log,
    ) {}

    public function find(Model $owner): ?Customer
    {
        return Customer::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->first();
    }

    /** Find or create the Payload customer account for $owner. */
    public function forOwner(Model $owner, ?string $name = null, ?string $email = null): Customer
    {
        if ($customer = $this->find($owner)) {
            return $customer;
        }

        $email ??= $owner->getAttribute('email');
        $name ??= $owner->getAttribute('name');

        // external_uid lets Payload dedupe if our row is ever lost.
        $account = $this->payload->createCustomerAccount($name, $email, $this->externalUid($owner));

        try {
            return Customer::query()->create([
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'payload_account_id' => $account['id'],
                'email_hash' => $email ? $this->fingerprinter->emailHash($email) : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->find($owner);
        }
    }

    /**
     * The owner's active saved cards, straight from Payload.
     *
     * @return Collection<int, array{id: string, brand: ?string, last4: ?string, expiry: ?string, holder: ?string}>
     */
    public function paymentMethods(Model $owner): Collection
    {
        $customer = $this->find($owner);

        if (! $customer) {
            return collect();
        }

        return collect($this->payload->listPaymentMethods($customer->payload_account_id))
            ->filter(fn (array $m) => ($m['type'] ?? 'card') === 'card' && ($m['status'] ?? 'active') === 'active')
            ->map(fn (array $m) => [
                'id' => $m['id'],
                'brand' => $m['card']['brand'] ?? null,
                'last4' => preg_match('/(\d{4})\s*$/', (string) ($m['card']['card_number'] ?? ''), $x) ? $x[1] : null,
                'expiry' => $m['card']['expiry'] ?? null,
                'holder' => $m['account_holder'] ?? null,
            ])
            ->values();
    }

    public function ownsPaymentMethod(Customer $customer, string $paymentMethodId): bool
    {
        try {
            $method = $this->payload->getPaymentMethod($paymentMethodId);
        } catch (PayloadException) {
            return false;
        }

        return ($method['account_id'] ?? null) === $customer->payload_account_id
            && ($method['status'] ?? 'active') === 'active';
    }

    public function removePaymentMethod(Model $owner, string $paymentMethodId): bool
    {
        $customer = $this->find($owner);

        if (! $customer || ! $this->ownsPaymentMethod($customer, $paymentMethodId)) {
            return false;
        }

        $this->payload->deletePaymentMethod($paymentMethodId);

        return true;
    }

    /**
     * After a paid checkout on an owner's account: keep the card if the
     * customer asked to save it, otherwise deactivate it so it doesn't show
     * up as a saved card.
     */
    public function settlePaymentMethod(Checkout $checkout): void
    {
        if (! $checkout->customer_id || ! $checkout->payment_method_id) {
            return;
        }

        try {
            $this->payload->updatePaymentMethod($checkout->payment_method_id, $checkout->save_payment_method
                ? ['keep_active' => true]
                : ['status' => 'inactive']);
        } catch (PayloadException $e) {
            $this->log->record('payment_method_settle_failed', 'info', ['error' => $e->getMessage()], $checkout->id);
        }
    }

    private function externalUid(Model $owner): string
    {
        return 'qp_' . substr($this->fingerprinter->hash('owner', $owner->getMorphClass() . ':' . $owner->getKey()), 0, 40);
    }
}
