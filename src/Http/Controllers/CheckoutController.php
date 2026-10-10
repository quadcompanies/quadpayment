<?php

namespace QuadCompanies\QuadPayment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use QuadCompanies\QuadPayment\Checkout\CheckoutManager;
use QuadCompanies\QuadPayment\Contracts\BotVerifier;
use QuadCompanies\QuadPayment\Exceptions\PaymentBlockedException;
use QuadCompanies\QuadPayment\Exceptions\PaymentVerificationException;
use QuadCompanies\QuadPayment\Models\Checkout;
use QuadCompanies\QuadPayment\Payload\PayloadClient;
use QuadCompanies\QuadPayment\Payload\PayloadException;
use QuadCompanies\QuadPayment\Security\Guard;
use QuadCompanies\QuadPayment\Security\SecurityLog;

/**
 * Browser-facing endpoints used by the card-fields component.
 */
class CheckoutController extends Controller
{
    /** Mint the client token that renders Payload's secure fields. */
    public function token(Request $request, CheckoutManager $checkouts, BotVerifier $bot, SecurityLog $log): JsonResponse
    {
        try {
            $checkout = $checkouts->fromRequest($request);
        } catch (PaymentVerificationException $e) {
            return $this->blocked($e->getMessage(), 'checkout_missing', true, 410);
        }

        // Re-rendering an already-minted token needs no new challenge; minting
        // one does.
        if (! $checkout->hasUsableToken() && ! $bot->verify($request)) {
            $log->record('bot_check_failed', 'info', [], $checkout->id, $request->ip());

            return $this->blocked('Please complete the security check and try again.', 'bot_check_failed', false, 422);
        }

        try {
            $token = $checkouts->issueToken($checkout, $request);
        } catch (PaymentBlockedException $e) {
            return $this->blocked($e->getMessage(), $e->reason(), $e->decision->isTerminal(), 429);
        } catch (PayloadException $e) {
            $log->record('token_failed', 'warning', ['error' => $e->getMessage()], $checkout->id, $request->ip());

            return $this->blocked('Payments are temporarily unavailable. Please try again shortly.', 'payload_error', false, 503);
        }

        return response()->json(['token' => $token])->header('Cache-Control', 'no-store');
    }

    /**
     * The browser reports a declined transaction id. It's only a hint: the
     * transaction is re-fetched from Payload and must belong to this checkout
     * before it's counted (the webhook reports the same thing independently).
     */
    public function declined(Request $request, CheckoutManager $checkouts, PayloadClient $payload, Guard $guard): JsonResponse
    {
        try {
            $checkout = $checkouts->fromRequest($request);
        } catch (PaymentVerificationException) {
            return response()->json(['locked' => true, 'remaining' => 0], 410);
        }

        $transactionId = $request->input('transaction_id');

        if (is_string($transactionId) && preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $transactionId)) {
            try {
                $transaction = $payload->getTransaction($transactionId);

                if ($transaction->senderAccountId() === $checkout->payload_account_id
                    && $transaction->orderNumber() === $checkout->reference) {
                    $guard->recordAttempt($transaction, $checkout, 'client');
                }
            } catch (PayloadException) {
                // The webhook will still report it.
            }
        }

        $checkout->refresh();
        $max = (int) config('quadpayment.guard.checkout.max_declines', 3);
        $locked = $checkout->status === Checkout::LOCKED;

        return response()->json([
            'locked' => $locked,
            'remaining' => $locked ? 0 : max(0, $max - $checkout->decline_count),
            'message' => $locked ? config('quadpayment.guard.messages.checkout_locked') : null,
        ]);
    }

    public function script(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        return response()->file(__DIR__ . '/../../../resources/js/quadpayment.js', [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function blocked(string $message, ?string $reason, bool $terminal, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'reason' => $reason,
            'blocked' => true,
            'terminal' => $terminal,
        ], $status)->header('Cache-Control', 'no-store');
    }
}
