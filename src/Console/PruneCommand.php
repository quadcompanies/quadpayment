<?php

namespace QuadCompanies\QuadPayment\Console;

use Illuminate\Console\Command;
use QuadCompanies\QuadPayment\Checkout\CheckoutManager;
use QuadCompanies\QuadPayment\Models\Attempt;
use QuadCompanies\QuadPayment\Models\Block;
use QuadCompanies\QuadPayment\Models\Checkout;
use QuadCompanies\QuadPayment\Models\SecurityEvent;
use QuadCompanies\QuadPayment\Models\Token;
use QuadCompanies\QuadPayment\Models\WebhookEvent;
use QuadCompanies\QuadPayment\Payload\PayloadClient;
use QuadCompanies\QuadPayment\Payload\PayloadException;

/**
 * Schedule every five minutes:
 *   Schedule::command('quadpayment:prune')->everyFiveMinutes();
 */
class PruneCommand extends Command
{
    protected $signature = 'quadpayment:prune';

    protected $description = 'Void abandoned authorizations, expire stale checkouts and delete old records';

    public function handle(PayloadClient $payload, CheckoutManager $checkouts): int
    {
        // Authorized but never captured: the customer's form never completed.
        // Release the hold on their card.
        $voidAfter = (int) config('quadpayment.checkout.authorization_void_after_minutes', 60);

        Checkout::query()
            ->where('status', Checkout::AUTHORIZED)
            ->where('authorized_at', '<', now()->subMinutes($voidAfter))
            ->each(function (Checkout $checkout) use ($payload, $checkouts) {
                try {
                    $checkouts->safeVoid($payload->getTransaction($checkout->transaction_id));
                    $checkout->forceFill(['status' => Checkout::VOIDED])->save();
                    $this->line("Voided abandoned authorization {$checkout->transaction_id}");
                } catch (PayloadException $e) {
                    $this->warn("Could not void {$checkout->transaction_id}: {$e->getMessage()}");
                }
            });

        // Open checkouts past their lifetime: revoke their tokens.
        Checkout::query()
            ->whereIn('status', [Checkout::PENDING, Checkout::READY])
            ->where('expires_at', '<', now())
            ->each(function (Checkout $checkout) use ($payload) {
                if ($checkout->intent_id) {
                    $payload->revokeIntent($checkout->intent_id);
                }
                $checkout->forceFill(['status' => Checkout::EXPIRED, 'client_token' => null])->save();
            });

        $cutoff = now()->subDays((int) config('quadpayment.retention_days', 90));

        $deleted = Attempt::query()->where('occurred_at', '<', $cutoff)->delete()
            + Token::query()->where('created_at', '<', $cutoff)->delete()
            + SecurityEvent::query()->where('created_at', '<', $cutoff)->delete()
            + WebhookEvent::query()->where('created_at', '<', $cutoff)->delete()
            + Block::query()->where('source', 'auto')->where('expires_at', '<', $cutoff)->delete()
            + Checkout::query()->whereIn('status', [Checkout::EXPIRED, Checkout::LOCKED, Checkout::VOIDED])->where('updated_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} old records.");

        return self::SUCCESS;
    }
}
