<?php

namespace QuadCompanies\QuadPayment\Console;

use Illuminate\Console\Command;
use QuadCompanies\QuadPayment\Payload\PayloadClient;

class WebhooksCommand extends Command
{
    protected $signature = 'quadpayment:webhooks {--url= : Override the endpoint URL} {--list : Only list existing webhooks}';

    protected $description = 'Register the Payload webhooks the guard relies on (signed with PAYLOAD_WEBHOOK_SECRET)';

    public function handle(PayloadClient $payload): int
    {
        $url = $this->option('url') ?: route('quadpayment.webhook');
        $existing = collect($payload->listWebhooks());

        if ($this->option('list')) {
            $this->table(['ID', 'Trigger', 'URL'], $existing->map(fn ($w) => [$w['id'] ?? '', $w['trigger'] ?? '', $w['url'] ?? '']));

            return self::SUCCESS;
        }

        $secret = config('quadpayment.webhooks.secret');

        if (! $secret || strlen($secret) < 32) {
            $this->error('Set PAYLOAD_WEBHOOK_SECRET to a random value of at least 32 characters (openssl rand -hex 32).');

            return self::FAILURE;
        }

        if (! str_starts_with($url, 'https://')) {
            $this->warn("Webhook URL is not HTTPS: {$url}");
        }

        foreach ((array) config('quadpayment.webhooks.triggers') as $trigger) {
            $already = $existing->first(fn ($w) => ($w['trigger'] ?? null) === $trigger && ($w['url'] ?? null) === $url);

            if ($already) {
                $this->line("✓ {$trigger} already registered ({$already['id']})");
                continue;
            }

            $created = $payload->createWebhook($trigger, $url, $secret, (int) config('quadpayment.webhooks.retries', 3));
            $this->info("+ {$trigger} registered ({$created['id']})");
        }

        return self::SUCCESS;
    }
}
