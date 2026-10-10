<?php

namespace QuadCompanies\QuadPayment\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CircuitBreakerTrippedNotification extends Notification
{
    public function __construct(
        public readonly string $reason,
        public readonly int $cooldownMinutes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $duration = $this->cooldownMinutes > 0
            ? "for {$this->cooldownMinutes} minutes"
            : 'until it is reset manually';

        return (new MailMessage)
            ->subject('[' . config('app.name') . '] Card payments paused — possible card testing')
            ->line("The payment circuit breaker has tripped and card payments are paused {$duration}.")
            ->line('Reason: ' . $this->reason)
            ->line('This usually means someone is testing stolen cards against the payment form. Review recent transactions in the Payload dashboard.')
            ->line('Status: `php artisan quadpayment:breaker status` — resume early: `php artisan quadpayment:breaker reset`.');
    }
}
