<?php

namespace QuadCompanies\QuadPayment\Http\Controllers;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use QuadCompanies\QuadPayment\Checkout\CheckoutManager;
use QuadCompanies\QuadPayment\Models\Checkout;
use QuadCompanies\QuadPayment\Models\WebhookEvent;
use QuadCompanies\QuadPayment\Payload\PayloadClient;
use QuadCompanies\QuadPayment\Payload\PayloadException;
use QuadCompanies\QuadPayment\Security\Actor;
use QuadCompanies\QuadPayment\Security\Fingerprinter;
use QuadCompanies\QuadPayment\Security\Guard;
use QuadCompanies\QuadPayment\Security\SecurityLog;

/**
 * Payload webhook receiver. This is how attempts made *directly* against
 * Payload with a harvested client token get counted — the browser never has
 * to tell us about them.
 *
 * The body is only a pointer: the signature is verified, and the transaction
 * is re-fetched from the API before anything is counted, so a forged POST
 * can't block anyone or trip the breaker.
 */
class WebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PayloadClient $payload,
        CheckoutManager $checkouts,
        Guard $guard,
        Fingerprinter $fingerprinter,
        SecurityLog $log,
    ): JsonResponse {
        if (! $this->signatureValid($request)) {
            $log->record('webhook_rejected', 'warning', ['reason' => 'bad_signature'], ip: $request->ip());

            return response()->json(['ok' => false], 401);
        }

        $eventId = $request->input('event_id');
        $objectId = $request->input('triggered_on.id');
        $object = $request->input('triggered_on.object');

        if (is_string($eventId) && $eventId !== '') {
            try {
                $event = WebhookEvent::query()->create([
                    'event_id' => $eventId,
                    'trigger' => $request->input('trigger'),
                    'object_id' => is_string($objectId) ? $objectId : null,
                    'status' => 'received',
                ]);
            } catch (UniqueConstraintViolationException) {
                return response()->json(['ok' => true, 'duplicate' => true]);
            }
        }

        if ($object !== 'transaction' || ! is_string($objectId) || $objectId === '') {
            isset($event) && $event->update(['status' => 'ignored']);

            return response()->json(['ok' => true]);
        }

        try {
            $transaction = $payload->getTransaction($objectId);
        } catch (PayloadException $e) {
            isset($event) && $event->update(['status' => 'failed']);
            $log->record('webhook_fetch_failed', 'warning', ['transaction_id' => $objectId, 'error' => $e->getMessage()]);

            // Non-2xx makes Payload retry.
            return response()->json(['ok' => false], 503);
        }

        if (! $transaction->isCard() && $transaction->method() !== []) {
            isset($event) && $event->update(['status' => 'ignored']);

            return response()->json(['ok' => true]);
        }

        $checkout = $checkouts->findForTransaction($transaction);
        $guard->recordAttempt($transaction, $checkout, 'webhook');

        // A success on a checkout that was locked (or with a card/actor that
        // is now blocked) is an attacker who got through with a token we
        // couldn't revoke — release it before anyone captures it.
        if ($checkout && $transaction->isSuccessful()) {
            $checkout->refresh();
            $ip = $transaction->initiatorIp() ?: $checkout->ip;
            $actor = new Actor($ip, $fingerprinter->subnet($ip), $checkout->session_hash, $checkout->email_hash);

            $denied = $checkout->status === Checkout::LOCKED
                || ($checkout->isOpen() && $guard->check($actor, null, $guard->cardHashFor($transaction))->denied());

            if ($denied) {
                $checkouts->safeVoid($transaction);
                $log->record('voided_on_webhook', 'warning', ['transaction_id' => $transaction->id()], $checkout->id, $ip);
            }
        }

        isset($event) && $event->update(['status' => 'processed']);

        return response()->json(['ok' => true]);
    }

    /** HMAC-SHA256 of the raw body with the webhook's sender_secret. */
    private function signatureValid(Request $request): bool
    {
        $secret = (string) config('quadpayment.webhooks.secret');
        $signature = (string) $request->header('X-Payload-Signature');

        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), strtolower($signature));
    }
}
