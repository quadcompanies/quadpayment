<?php

namespace QuadCompanies\QuadPayment\Security;

use Illuminate\Support\Facades\Notification;
use QuadCompanies\QuadPayment\Events\CircuitBreakerReset;
use QuadCompanies\QuadPayment\Events\CircuitBreakerTripped;
use QuadCompanies\QuadPayment\Models\Setting;
use QuadCompanies\QuadPayment\Notifications\CircuitBreakerTrippedNotification;

/**
 * Site-wide kill switch for card processing. When tripped, no client tokens
 * are minted, open checkouts can't be attempted, and authorizations that
 * arrive are voided instead of captured.
 *
 * State lives in the settings table (not cache) so a cache flush can't
 * silently re-open payments during an attack.
 */
class CircuitBreaker
{
    private const KEY = 'breaker';

    private ?array $state = null;

    private int $loadedAt = 0;

    public function __construct(
        private Thresholds $thresholds,
        private SecurityLog $log,
    ) {}

    public function isTripped(): bool
    {
        $state = $this->state();

        if (! $state) {
            return false;
        }

        if (! empty($state['until']) && now()->timestamp >= $state['until']) {
            $this->reset('cooldown elapsed');

            return false;
        }

        return true;
    }

    public function state(): ?array
    {
        // Re-read every few seconds so long-lived workers (Octane, queues)
        // see trips and resets made by other processes.
        if ($this->state === null || time() - $this->loadedAt > 5) {
            $this->state = Setting::read(self::KEY) ?: [];
            $this->loadedAt = time();
        }

        return $this->state;
    }

    /** Returns false when already tripped (so alerts fire once per trip). */
    public function trip(string $reason, array $context = []): bool
    {
        if ($this->isTripped()) {
            return false;
        }

        $cooldown = (int) $this->thresholds->get('breaker.cooldown_minutes', 60);

        $this->state = [
            'reason' => $reason,
            'tripped_at' => now()->timestamp,
            'until' => $cooldown > 0 ? now()->addMinutes($cooldown)->timestamp : null,
        ];

        Setting::write(self::KEY, $this->state);
        $this->loadedAt = time();

        $this->log->record('breaker_tripped', 'critical', ['reason' => $reason] + $context);

        event(new CircuitBreakerTripped($reason, $context));

        if ($to = config('quadpayment.guard.alert.mail_to')) {
            try {
                Notification::route('mail', $to)->notify(new CircuitBreakerTrippedNotification($reason, $cooldown));
            } catch (\Throwable $e) {
                $this->log->record('alert_failed', 'warning', ['error' => $e->getMessage()]);
            }
        }

        return true;
    }

    public function reset(string $by = 'manual'): void
    {
        $was = $this->state();

        Setting::forget(self::KEY);
        $this->state = [];

        if ($was) {
            $this->log->record('breaker_reset', 'warning', ['by' => $by, 'reason_was' => $was['reason'] ?? null]);
            event(new CircuitBreakerReset($by));
        }
    }

    public function refresh(): void
    {
        $this->state = null;
    }
}
