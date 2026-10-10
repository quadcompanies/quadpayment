<?php

namespace QuadCompanies\QuadPayment\Security;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use QuadCompanies\QuadPayment\Events\PaymentDeclined;
use QuadCompanies\QuadPayment\Models\Attempt;
use QuadCompanies\QuadPayment\Models\Block;
use QuadCompanies\QuadPayment\Models\Checkout;
use QuadCompanies\QuadPayment\Models\SecurityEvent;
use QuadCompanies\QuadPayment\Models\Token;
use QuadCompanies\QuadPayment\Payload\PayloadClient;
use QuadCompanies\QuadPayment\Payload\Transaction;

/**
 * Card-testing defenses.
 *
 * Layers, from cheapest to most drastic:
 *  1. Client tokens are rate limited per IP, subnet, session and email, and
 *     only minted after a bot check — a script can't attempt cards without one.
 *  2. Each checkout allows a few declines, then locks and its token is revoked.
 *  3. Weighted decline counts and distinct-card counts per IP / subnet / email /
 *     session / account / card trigger escalating blocks (1h → 24h → 7d).
 *  4. A site-wide decline spike trips the circuit breaker: all card
 *     processing stops and an alert goes out.
 *
 * Every number comes from the qp_attempts / qp_tokens tables, fed by Payload
 * webhooks and server-side verification — attempts a script makes directly
 * against Payload with a harvested token are still counted.
 */
class Guard
{
    public function __construct(
        private Thresholds $thresholds,
        private BlockList $blocks,
        private CircuitBreaker $breaker,
        private SecurityLog $log,
        private Fingerprinter $fingerprinter,
        private DeclineClassifier $classifier,
        private PayloadClient $payload,
        private array $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    public function breaker(): CircuitBreaker
    {
        return $this->breaker;
    }

    public function blocks(): BlockList
    {
        return $this->blocks;
    }

    // ---- Gates -------------------------------------------------------------

    /** May this actor attempt a card on this checkout right now? */
    public function check(Actor $actor, ?Checkout $checkout = null, ?string $cardHash = null): Decision
    {
        if (! $this->enabled()) {
            return Decision::allow();
        }

        if ($this->breaker->isTripped()) {
            return Decision::deny('circuit_breaker', $this->message('breaker'));
        }

        if ($checkout && $checkout->status === Checkout::LOCKED) {
            return Decision::deny('checkout_locked', $this->message('checkout_locked'));
        }

        if ($this->isAllowListed($actor->ip)) {
            return Decision::allow();
        }

        if ($block = $this->blocks->find($actor, $cardHash)) {
            return Decision::deny('blocked_' . $block->type, $this->message('blocked'));
        }

        return Decision::allow();
    }

    /** May we mint a new client token for this actor? */
    public function checkTokenIssuance(Actor $actor, Checkout $checkout): Decision
    {
        $decision = $this->check($actor, $checkout);

        if ($decision->denied() || ! $this->enabled()) {
            return $decision;
        }

        // A flood of token requests across the whole site is an attack in
        // progress even when each source stays under its own limit.
        $global = Token::query()->where('created_at', '>=', now()->subMinutes(10))->count();

        if ($global >= (int) $this->thresholds->get('tokens.global_per_10_minutes', 120)) {
            $this->tripBreaker(sprintf('Site-wide token surge: %d client tokens in 10 minutes', $global));

            return Decision::deny('circuit_breaker', $this->message('breaker'));
        }

        if ($this->isAllowListed($actor->ip)) {
            return Decision::allow();
        }

        $limits = [
            ['ip', $actor->ip, 'tokens.per_ip_per_hour', 60],
            ['subnet', $actor->subnet, 'tokens.per_subnet_per_hour', 60],
            ['session_hash', $actor->sessionHash, 'tokens.per_session_per_hour', 60],
            ['email_hash', $actor->emailHash, 'tokens.per_email_per_day', 1440],
        ];

        foreach ($limits as [$column, $value, $limit, $minutes]) {
            if (! $value) {
                continue;
            }

            $count = Token::query()
                ->where($column, $value)
                ->where('created_at', '>=', now()->subMinutes($minutes))
                ->count();

            $max = (int) $this->thresholds->get($limit);

            if ($count >= $max) {
                $this->log->record('token_denied', 'warning', ['limit' => $limit, 'count' => $count], $checkout->id, $actor->ip);

                // Hammering on after being refused as often as the limit
                // itself isn't a confused customer.
                $refusals = SecurityEvent::query()
                    ->where('type', 'token_denied')
                    ->where('ip', $actor->ip)
                    ->where('created_at', '>=', now()->subHour())
                    ->count();

                if ($actor->ip && $refusals >= (int) $this->thresholds->get('tokens.per_ip_per_hour', 5)) {
                    $this->blocks->escalate(Block::IP, $actor->ip, "Token farming: refused {$refusals} times in an hour");
                }

                return Decision::deny('token_rate_' . $column, $this->message('blocked'));
            }
        }

        return Decision::allow();
    }

    public function recordTokenIssued(Actor $actor, Checkout $checkout, ?string $intentId): void
    {
        Token::query()->create([
            'checkout_id' => $checkout->id,
            'intent_id' => $intentId,
            'ip' => $actor->ip,
            'subnet' => $actor->subnet,
            'session_hash' => $actor->sessionHash,
            'email_hash' => $actor->emailHash,
            'created_at' => now(),
        ]);
    }

    // ---- Recording outcomes ------------------------------------------------

    /**
     * Record a transaction Payload reported (always re-fetched from the API,
     * never taken from a request body) and react to it. Idempotent: the
     * webhook, the browser report and verification can all report the same
     * transaction and it is counted once.
     */
    public function recordAttempt(Transaction $transaction, ?Checkout $checkout, string $source): ?Attempt
    {
        if (! $transaction->id() || ($transaction->type() && $transaction->type() !== 'payment')) {
            return null;
        }

        $existing = Attempt::query()->where('transaction_id', $transaction->id())->first();

        if ($existing) {
            $becameDecline = ! $existing->isDecline() && $transaction->isDeclined();

            $existing->update(['status' => $transaction->status(), 'status_code' => $transaction->statusCode()]);

            if ($becameDecline) {
                $existing->update(['weight' => $this->classifier->weight($transaction)]);
                $this->afterDecline($existing, $checkout);
            }

            return $existing;
        }

        $ip = $transaction->initiatorIp() ?: $checkout?->ip;

        try {
            $attempt = Attempt::query()->create([
                'checkout_id' => $checkout?->id,
                'transaction_id' => $transaction->id(),
                'payload_account_id' => $transaction->senderAccountId(),
                'status' => $transaction->status() ?? 'unknown',
                'status_code' => $transaction->statusCode(),
                'weight' => $this->classifier->weight($transaction),
                'amount' => $transaction->amountInCents() !== null ? $transaction->amountInCents() / 100 : null,
                'card_hash' => $this->fingerprinter->cardHash($transaction->cardBrand(), $transaction->cardLast4(), $transaction->cardExpiry()),
                'ip' => $ip,
                'subnet' => $this->fingerprinter->subnet($ip),
                'email_hash' => $checkout?->email_hash,
                'session_hash' => $checkout?->session_hash,
                'source' => $source,
                'occurred_at' => $transaction->createdAt() ?? now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Webhook and browser report raced; the other one recorded it.
            return Attempt::query()->where('transaction_id', $transaction->id())->first();
        }

        if (! $this->enabled()) {
            return $attempt;
        }

        if ($attempt->isDecline()) {
            $this->afterDecline($attempt, $checkout);
        } else {
            // Many *successful* cards from one source — or one card succeeding
            // from many places — is card testing too.
            $this->assessActor($attempt);
            $this->assessCard($attempt);
            $this->evaluateBreaker();
        }

        return $attempt;
    }

    private function afterDecline(Attempt $attempt, ?Checkout $checkout): void
    {
        event(new PaymentDeclined($attempt));

        if ($checkout) {
            $checkout->increment('decline_count');
            $checkout->increment('decline_score', $attempt->weight);

            if ($checkout->decline_count >= (int) $this->thresholds->get('checkout.max_declines', 3)
                && $checkout->isOpen()) {
                $this->lockCheckout($checkout, 'Too many declines on one checkout');
            }
        }

        if (! $this->enabled()) {
            return;
        }

        $this->assessActor($attempt);
        $this->assessCard($attempt);
        $this->evaluateBreaker();
    }

    // ---- Assessment --------------------------------------------------------

    private function assessActor(Attempt $attempt): void
    {
        if ($this->isAllowListed($attempt->ip)) {
            return;
        }

        $rules = [
            // [column, value, block type, threshold key, window minutes, metric]
            ['ip', $attempt->ip, Block::IP, 'declines.per_ip_per_hour', 60, 'declines'],
            ['subnet', $attempt->subnet, Block::SUBNET, 'declines.per_subnet_per_hour', 60, 'declines'],
            ['email_hash', $attempt->email_hash, Block::EMAIL, 'declines.per_email_per_day', 1440, 'declines'],
            ['ip', $attempt->ip, Block::IP, 'distinct_cards.per_ip_per_day', 1440, 'cards'],
            ['email_hash', $attempt->email_hash, Block::EMAIL, 'distinct_cards.per_email_per_day', 1440, 'cards'],
            ['session_hash', $attempt->session_hash, Block::SESSION, 'distinct_cards.per_session', 1440, 'cards'],
            ['payload_account_id', $attempt->payload_account_id, Block::ACCOUNT, 'distinct_cards.per_account_per_day', 1440, 'cards'],
        ];

        foreach ($rules as [$column, $value, $type, $key, $minutes, $metric]) {
            if (! $value) {
                continue;
            }

            $query = Attempt::query()->where($column, $value)->where('occurred_at', '>=', now()->subMinutes($minutes));
            $limit = (float) $this->thresholds->get($key);

            if ($metric === 'declines') {
                $score = (float) (clone $query)->whereIn('status', ['declined', 'rejected'])->sum('weight');
                $tripped = $score >= $limit;
                $detail = sprintf('decline score %.2f in %d min (limit %s)', $score, $minutes, $limit);
            } else {
                $cards = $this->distinctCards($query);
                $tripped = $cards > $limit;
                $detail = sprintf('%d distinct cards in %d min (limit %s)', $cards, $minutes, $limit);
            }

            if ($tripped) {
                $this->blocks->escalate($type, $value, $key . ': ' . $detail);
                $this->lockOpenCheckouts($column === 'payload_account_id' ? 'payload_account_id' : $column, $value, 'Actor blocked');
            }
        }
    }

    private function assessCard(Attempt $attempt): void
    {
        if (! $attempt->card_hash) {
            return;
        }

        $since = now()->subDay();

        $score = (float) Attempt::query()
            ->where('card_hash', $attempt->card_hash)
            ->whereIn('status', ['declined', 'rejected'])
            ->where('occurred_at', '>=', $since)
            ->sum('weight');

        if ($score >= (float) $this->thresholds->get('declines.per_card_per_day', 3)) {
            $this->blocks->escalate(Block::CARD, $attempt->card_hash, sprintf('Card declined repeatedly (score %.2f in 24h)', $score));
        }

        $ips = Attempt::query()
            ->where('card_hash', $attempt->card_hash)
            ->where('occurred_at', '>=', $since)
            ->whereNotNull('ip')
            ->distinct()
            ->count('ip');

        if ($ips > (int) $this->thresholds->get('card_spread.max_ips_per_card_per_day', 3)) {
            $this->blocks->escalate(Block::CARD, $attempt->card_hash, "Same card tried from {$ips} IPs in 24h");
        }
    }

    /** Site-wide decline spike → stop all card processing. */
    public function evaluateBreaker(): void
    {
        if (! $this->enabled() || $this->breaker->isTripped()) {
            return;
        }

        $minutes = (int) $this->thresholds->get('breaker.window_minutes', 15);
        $window = Attempt::query()->where('occurred_at', '>=', now()->subMinutes($minutes));

        $total = (clone $window)->count();
        $declined = (clone $window)->whereIn('status', ['declined', 'rejected']);
        $declineCount = (clone $declined)->count();
        $score = (float) (clone $declined)->sum('weight');
        $declinedCards = $this->distinctCards(clone $declined);
        $declinedIps = (int) (clone $declined)->whereNotNull('ip')->distinct()->count('ip');

        $context = compact('total', 'declineCount', 'score', 'declinedCards', 'declinedIps', 'minutes');

        if ($score >= (float) $this->thresholds->get('breaker.max_declines', 12)) {
            $this->tripBreaker(sprintf('Decline spike: score %.1f (%d declines) in %d minutes', $score, $declineCount, $minutes), $context);
        } elseif ($total >= (int) $this->thresholds->get('breaker.min_sample', 10)
            // One unlucky customer on a quiet site mustn't skew the ratio;
            // single-source bursts are the per-actor limits' job.
            && $declinedIps >= (int) $this->thresholds->get('breaker.ratio_min_sources', 3)
            && $declineCount / max($total, 1) >= (float) $this->thresholds->get('breaker.max_decline_ratio', 0.6)) {
            $this->tripBreaker(sprintf('Decline ratio %d%% (%d of %d) in %d minutes', round(100 * $declineCount / $total), $declineCount, $total, $minutes), $context);
        } elseif ($declinedCards >= (int) $this->thresholds->get('breaker.max_distinct_declined_cards', 8)) {
            $this->tripBreaker(sprintf('%d different cards declined in %d minutes', $declinedCards, $minutes), $context);
        }
    }

    public function tripBreaker(string $reason, array $context = []): void
    {
        if ($this->breaker->trip($reason, $context)) {
            // Tokens already handed out could still be used directly against
            // Payload — revoke every one that's still live.
            Checkout::query()
                ->where('status', Checkout::READY)
                ->where('token_expires_at', '>', now())
                ->limit(500)
                ->get()
                ->each(fn (Checkout $c) => $this->lockCheckout($c, 'Circuit breaker tripped', log: false));
        }
    }

    // ---- Helpers -----------------------------------------------------------

    public function lockCheckout(Checkout $checkout, string $reason, bool $log = true): void
    {
        $checkout->forceFill(['status' => Checkout::LOCKED, 'client_token' => null])->save();

        if ($checkout->intent_id) {
            $this->payload->revokeIntent($checkout->intent_id);
        }

        if ($log) {
            $this->log->record('checkout_locked', 'warning', ['reason' => $reason, 'declines' => $checkout->decline_count], $checkout->id, $checkout->ip);
        }
    }

    private function lockOpenCheckouts(string $column, string $value, string $reason): void
    {
        if (! in_array($column, ['ip', 'subnet', 'email_hash', 'session_hash', 'payload_account_id'], true)) {
            return;
        }

        Checkout::query()
            ->whereIn('status', [Checkout::PENDING, Checkout::READY])
            ->where($column, $value)
            ->limit(100)
            ->get()
            ->each(fn (Checkout $c) => $this->lockCheckout($c, $reason));
    }

    private function distinctCards(Builder $query): int
    {
        return (int) $query->whereNotNull('card_hash')->distinct()->count('card_hash');
    }

    public function isAllowListed(?string $ip): bool
    {
        return $ip && in_array($ip, (array) ($this->config['allow_ips'] ?? []), true);
    }

    public function cardHashFor(Transaction $transaction): ?string
    {
        return $this->fingerprinter->cardHash($transaction->cardBrand(), $transaction->cardLast4(), $transaction->cardExpiry());
    }

    private function message(string $key): string
    {
        return $this->config['messages'][$key] ?? 'Payments are temporarily unavailable.';
    }
}
