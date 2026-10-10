<?php

namespace QuadCompanies\QuadPayment\Console;

use Illuminate\Console\Command;
use QuadCompanies\QuadPayment\Models\Attempt;
use QuadCompanies\QuadPayment\Models\Block;
use QuadCompanies\QuadPayment\Models\SecurityEvent;
use QuadCompanies\QuadPayment\Models\Token;
use QuadCompanies\QuadPayment\Security\CircuitBreaker;

class StatusCommand extends Command
{
    protected $signature = 'quadpayment:status {--minutes=60}';

    protected $description = 'Card-testing dashboard: breaker, recent attempts, declines and blocks';

    public function handle(CircuitBreaker $breaker): int
    {
        $minutes = (int) $this->option('minutes');
        $since = now()->subMinutes($minutes);

        $attempts = Attempt::query()->where('occurred_at', '>=', $since);
        $total = (clone $attempts)->count();
        $declined = (clone $attempts)->whereIn('status', ['declined', 'rejected'])->count();

        $this->line($breaker->isTripped()
            ? '<error> BREAKER TRIPPED </error> ' . ($breaker->state()['reason'] ?? '')
            : '<info>Breaker closed</info>');

        $this->table(["Last {$minutes} min", 'Count'], [
            ['Client tokens issued', Token::query()->where('created_at', '>=', $since)->count()],
            ['Card attempts', $total],
            ['Declined', $declined . ($total ? sprintf(' (%d%%)', round(100 * $declined / $total)) : '')],
            ['Distinct cards', (clone $attempts)->whereNotNull('card_hash')->distinct()->count('card_hash')],
            ['Distinct IPs', (clone $attempts)->whereNotNull('ip')->distinct()->count('ip')],
            ['Active blocks', Block::query()->active()->count()],
        ]);

        $top = (clone $attempts)->whereIn('status', ['declined', 'rejected'])
            ->selectRaw('ip, count(*) as declines, sum(weight) as score')
            ->groupBy('ip')->orderByDesc('declines')->limit(5)->get();

        if ($top->isNotEmpty()) {
            $this->table(['IP', 'Declines', 'Score'], $top->map(fn ($r) => [$r->ip, $r->declines, $r->score]));
        }

        $events = SecurityEvent::query()->where('created_at', '>=', $since)->where('severity', '!=', 'info')
            ->latest('id')->limit(10)->get();

        if ($events->isNotEmpty()) {
            $this->table(['When', 'Event', 'Severity', 'IP'], $events->map(fn ($e) => [$e->created_at, $e->type, $e->severity, $e->ip]));
        }

        return self::SUCCESS;
    }
}
