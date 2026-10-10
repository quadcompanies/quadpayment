<?php

namespace QuadCompanies\QuadPayment\Console;

use Illuminate\Console\Command;
use QuadCompanies\QuadPayment\Security\CircuitBreaker;
use QuadCompanies\QuadPayment\Security\Guard;

class BreakerCommand extends Command
{
    protected $signature = 'quadpayment:breaker {action=status : status | trip | reset} {--reason=Manual trip}';

    protected $description = 'Show, trip or reset the card-processing circuit breaker';

    public function handle(CircuitBreaker $breaker, Guard $guard): int
    {
        switch ($this->argument('action')) {
            case 'trip':
                $guard->tripBreaker($this->option('reason'));
                $this->warn('Circuit breaker tripped. Card processing is paused.');
                break;

            case 'reset':
                $breaker->reset('artisan');
                $this->info('Circuit breaker reset. Card processing resumed.');
                break;

            default:
                if (! $breaker->isTripped()) {
                    $this->info('Circuit breaker: closed (card processing available).');
                    break;
                }

                $state = $breaker->state();
                $this->warn('Circuit breaker: TRIPPED');
                $this->line('Reason: ' . ($state['reason'] ?? '?'));
                $this->line('Since:  ' . date('Y-m-d H:i:s', $state['tripped_at'] ?? time()));
                $this->line('Until:  ' . (! empty($state['until']) ? date('Y-m-d H:i:s', $state['until']) : 'manual reset'));
        }

        return self::SUCCESS;
    }
}
