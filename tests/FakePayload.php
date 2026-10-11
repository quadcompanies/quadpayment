<?php

namespace QuadCompanies\QuadPayment\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stateful stand-in for the Payload v2 API, recording every request.
 */
class FakePayload
{
    public array $accounts = [];
    public array $intents = [];
    public array $transactions = [];
    public array $methods = [];
    public array $revoked = [];
    public array $webhooks = [];
    public array $requests = [];
    private int $seq = 0;

    public static function install(): self
    {
        $fake = new self;
        Http::fake(['api.payload.com/*' => fn (Request $r) => $fake->handle($r)]);

        return $fake;
    }

    public function handle(Request $request)
    {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $method = $request->method();
        $data = $request->data();
        $this->requests[] = [$method, $path, $data, $request->header('X-API-Version')[0] ?? null];

        // The real API rejects a bodyless request that declares a JSON body
        // (GET/DELETE with Content-Type: application/json) with this 400, so
        // the fake does too.
        if (in_array($method, ['GET', 'DELETE'], true)
            && str_contains($request->header('Content-Type')[0] ?? '', 'json')
            && $request->body() === '') {
            return Http::response([
                'object' => 'error',
                'error_description' => '400 Bad Request: The browser (or proxy) sent a request that this server could not understand.',
            ], 400);
        }

        return match (true) {
            $method === 'POST' && $path === '/accounts' => $this->json($this->accounts[$id = 'acct_' . ++$this->seq] = ['id' => $id] + $data),
            $method === 'POST' && $path === '/intents' => $this->json($this->intents[$id = 'int_' . ++$this->seq] = ['id' => $id, 'token' => 'client_token_' . $this->seq, 'status' => 'pending'] + $data),
            $method === 'DELETE' && str_starts_with($path, '/intents/') => $this->json(['id' => $this->revoked[] = basename($path)]),
            $method === 'GET' && str_starts_with($path, '/transactions/') => isset($this->transactions[basename($path)])
                ? $this->json($this->transactions[basename($path)])
                : Http::response(['object' => 'error', 'error_type' => 'NotFound', 'error_description' => 'not found'], 404),
            $method === 'PUT' && str_starts_with($path, '/transactions/') => $this->json($this->updateTransaction(basename($path), $data)),
            $method === 'POST' && $path === '/transactions' => $this->json($this->createTransaction($data)),
            $method === 'GET' && str_starts_with($path, '/payment_methods/') => isset($this->methods[basename($path)])
                ? $this->json($this->methods[basename($path)])
                : Http::response(['object' => 'error'], 404),
            $method === 'GET' && $path === '/payment_methods' => $this->json(['object' => 'list', 'values' => array_values(array_filter($this->methods, fn ($m) => $m['account_id'] === ($data['account_id'] ?? null)))]),
            $method === 'PUT' && str_starts_with($path, '/payment_methods/') => $this->json($this->methods[basename($path)] = array_replace($this->methods[basename($path)] ?? [], $data)),
            $method === 'GET' && $path === '/webhooks' => $this->json(['object' => 'list', 'values' => array_values($this->webhooks)]),
            $method === 'POST' && $path === '/webhooks' => $this->json($this->webhooks[$id = 'wh_' . ++$this->seq] = ['id' => $id] + $data),
            default => Http::response(['object' => 'error', 'error_description' => "unhandled {$method} {$path}"], 500),
        };
    }

    /** Simulate the browser completing (or failing) the payment form for an intent. */
    public function payIntent(string $token, string $status = 'authorized', ?string $code = null, string $last4 = '4242', string $expiry = '12/30', ?string $ip = '203.0.113.10', array $overrides = []): string
    {
        $intent = collect($this->intents)->firstWhere('token', $token);
        $template = $intent['payment_form']['payment_template'];
        $id = 'txn_' . ++$this->seq;
        $pm = 'pm_' . $this->seq;

        $this->methods[$pm] = [
            'id' => $pm, 'type' => 'card', 'status' => 'active', 'account_id' => $template['sender']['account_id'],
            'card' => ['brand' => 'visa', 'card_number' => 'xxxxxxxxxxxx' . $last4, 'expiry' => $expiry],
        ];

        $this->transactions[$id] = array_replace_recursive([
            'id' => $id,
            'object' => 'transaction',
            'type' => 'payment',
            'amount' => $template['amount'],
            'order_number' => $template['order_number'],
            'attrs' => $template['attrs'] ?? [],
            'status' => ['value' => $status, 'code' => $code, 'message' => $code ? 'Declined: ' . $code : null],
            'sender' => ['account_id' => $template['sender']['account_id'], 'method_id' => $pm],
            'receiver' => ['account_id' => $template['receiver']['account_id'] ?? null],
            'initiator' => ['ip' => $ip],
            'created_at' => now()->toIso8601String(),
        ], $overrides);

        return $id;
    }

    public function decline(string $token, string $code = 'invalid_card_code', string $last4 = '4242', ?string $ip = '203.0.113.10'): string
    {
        return $this->payIntent($token, 'declined', $code, $last4, '12/30', $ip);
    }

    public function sent(string $method, string $pathPrefix): array
    {
        return array_values(array_filter($this->requests, fn ($r) => $r[0] === $method && str_starts_with($r[1], $pathPrefix)));
    }

    private function updateTransaction(string $id, array $data): array
    {
        if (isset($data['status']['value'])) {
            $this->transactions[$id]['status']['value'] = $data['status']['value'];
        }

        return $this->transactions[$id];
    }

    private function createTransaction(array $data): array
    {
        $id = 'txn_' . ++$this->seq;

        return $this->transactions[$id] = $data + [
            'id' => $id,
            'status' => ['value' => 'processed'],
            'created_at' => now()->toIso8601String(),
        ];
    }

    private function json(array $body)
    {
        return Http::response($body + ['object' => 'object'], 200);
    }
}
