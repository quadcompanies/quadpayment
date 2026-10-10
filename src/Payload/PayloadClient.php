<?php

namespace QuadCompanies\QuadPayment\Payload;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;

/**
 * Thin wrapper over the Payload v2 REST API. Only ever handles identifiers
 * (acct_, pm_, txn_, intent ids) and tokens — no request or response that
 * passes through here carries a full card number or security code.
 */
class PayloadClient
{
    public function __construct(
        private HttpFactory $http,
        private array $config,
    ) {}

    public function processingAccountId(): ?string
    {
        return $this->config['processing_account_id'] ?? null;
    }

    // ---- Accounts ----------------------------------------------------------

    public function createCustomerAccount(?string $name, ?string $email, ?string $externalUid = null): array
    {
        return $this->send('post', '/accounts', array_filter([
            'type' => 'customer',
            'name' => $name,
            'email' => $email,
            'external_uid' => $externalUid,
        ]));
    }

    public function getAccount(string $id): array
    {
        return $this->send('get', '/accounts/' . rawurlencode($id));
    }

    // ---- Intents -----------------------------------------------------------

    /**
     * A payment_form intent fixes the amount, payer account and order number
     * server-side; the returned token only lets the browser complete exactly
     * this payment.
     */
    public function createPaymentFormIntent(array $paymentTemplate): array
    {
        return $this->send('post', '/intents', [
            'type' => 'payment_form',
            'payment_form' => ['payment_template' => $paymentTemplate],
        ]);
    }

    /** Collect and save a card to an account without charging it. */
    public function createPaymentMethodFormIntent(string $accountId, array $template = []): array
    {
        return $this->send('post', '/intents', [
            'type' => 'payment_method_form',
            'payment_method_form' => [
                'payment_method_template' => ['account_id' => $accountId] + $template,
            ],
        ]);
    }

    /** Best effort: stop a client token from being used for further attempts. */
    public function revokeIntent(string $intentId): bool
    {
        try {
            $this->send('delete', '/intents/' . rawurlencode($intentId));

            return true;
        } catch (PayloadException) {
            return false;
        }
    }

    // ---- Transactions ------------------------------------------------------

    public function getTransaction(string $id): Transaction
    {
        $data = $this->send('get', '/transactions/' . rawurlencode($id), ['fields' => '*,sender']);

        // Card details live on the payment method; fetch it when the
        // transaction response doesn't embed it.
        $methodId = Arr::get($data, 'sender.method_id');

        if (! Arr::has($data, 'sender.method.card') && $methodId) {
            try {
                Arr::set($data, 'sender.method', $this->getPaymentMethod($methodId));
            } catch (PayloadException) {
                // Card fingerprint is a nice-to-have; verification doesn't need it.
            }
        }

        return new Transaction($data);
    }

    /** Charge a saved payment method server-side (no browser involved). */
    public function createPayment(array $attributes): Transaction
    {
        return new Transaction($this->send('post', '/transactions', ['type' => 'payment'] + $attributes));
    }

    public function captureTransaction(string $id): Transaction
    {
        return new Transaction($this->send('put', '/transactions/' . rawurlencode($id), [
            'status' => ['value' => 'processed'],
        ]));
    }

    public function voidTransaction(string $id): Transaction
    {
        return new Transaction($this->send('put', '/transactions/' . rawurlencode($id), [
            'status' => ['value' => 'voided'],
        ]));
    }

    // ---- Payment methods ---------------------------------------------------

    public function getPaymentMethod(string $id): array
    {
        return $this->send('get', '/payment_methods/' . rawurlencode($id));
    }

    public function listPaymentMethods(string $accountId): array
    {
        $response = $this->send('get', '/payment_methods', [
            'account_id' => $accountId,
            'status' => 'active',
        ]);

        return $response['values'] ?? [];
    }

    public function updatePaymentMethod(string $id, array $attributes): array
    {
        return $this->send('put', '/payment_methods/' . rawurlencode($id), $attributes);
    }

    public function deletePaymentMethod(string $id): void
    {
        $this->send('delete', '/payment_methods/' . rawurlencode($id));
    }

    // ---- Webhooks ----------------------------------------------------------

    public function listWebhooks(): array
    {
        return $this->send('get', '/webhooks')['values'] ?? [];
    }

    public function createWebhook(string $trigger, string $url, ?string $secret, int $retries = 0): array
    {
        return $this->send('post', '/webhooks', array_filter([
            'trigger' => $trigger,
            'url' => $url,
            'sender_secret' => $secret,
            'retries' => $retries,
        ], fn ($v) => $v !== null));
    }

    public function deleteWebhook(string $id): void
    {
        $this->send('delete', '/webhooks/' . rawurlencode($id));
    }

    // ---- Transport ---------------------------------------------------------

    public function send(string $method, string $path, array $data = []): array
    {
        if (empty($this->config['api_key'])) {
            throw new PayloadException('Payload API key is not configured (PAYLOAD_API_SECRET).');
        }

        try {
            $response = $this->request()->{$method}($path, $data);
        } catch (\Throwable $e) {
            throw new PayloadException('Payload API unreachable: ' . $e->getMessage(), 0, null, $e);
        }

        return $this->decode($response);
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim($this->config['base_url'], '/'))
            ->withBasicAuth($this->config['api_key'], '')
            ->withHeaders(['X-API-Version' => $this->config['api_version'] ?? 'v2.0'])
            ->acceptJson()
            ->asJson()
            ->timeout($this->config['timeout'] ?? 15);
    }

    private function decode(Response $response): array
    {
        $body = $response->json() ?? [];

        if ($response->failed() || ($body['object'] ?? null) === 'error') {
            throw new PayloadException(
                $body['error_description'] ?? ('Payload API error ' . $response->status()),
                $response->status(),
                $body['error_type'] ?? null,
                null,
                $body['details'] ?? [],
            );
        }

        return $body;
    }
}
