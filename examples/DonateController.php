<?php

/*
 * The original DonateController (Payload SDK + CardTestingGuard) rewritten on top of quadpayment.
 *
 * Gone from the app: Payload SDK calls, customer/token bookkeeping in the
 * session, IP throttling, usleep() tarpits, validate-attempt and
 * track-attempt endpoints, and transaction verification — the package owns
 * all of it. What's left is the donation logic.
 */

namespace App\Http\Controllers;

use App\Mail\NewDonation;
use App\Models\Donation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use QuadCompanies\QuadPayment\Exceptions\PaymentBlockedException;
use QuadCompanies\QuadPayment\Exceptions\PaymentVerificationException;
use QuadCompanies\QuadPayment\Facades\QuadPayment;
use RyanChandler\LaravelCloudflareTurnstile\Rules\Turnstile;

class DonateController extends Controller
{
    // step1() and start() are unchanged: step 1 collects name/email/amount
    // behind Turnstile and stores them in session('donation').

    public function step2(Request $request)
    {
        if (! session()->has('donation')) {
            return redirect()->route('donate.step1');
        }

        $donation = session('donation');
        $total = $donation['amount'];

        if (! empty($donation['cover_fee'])) {
            $total = round(($total + 0.20) / (1 - 0.0285), 2);
        }

        // Amount, description and payer are fixed server-side here; the
        // browser can't change them. Reloading reuses the same checkout.
        $checkout = QuadPayment::checkout('donation')
            ->amount($total)
            ->description('Donation to Signature Gives Back')
            ->name($donation['full_name'])
            ->email($donation['email'])
            ->metadata(['amount' => $donation['amount'], 'phone' => $donation['phone']])
            ->create($request);

        return view('donate.index', [
            'step' => 2,
            'donation' => $donation,
            'checkout' => $checkout,
            'display_amount' => number_format($total, 2),
        ]);
    }

    public function complete(Request $request)
    {
        // Validate your own fields first: if this fails, the card is still
        // only authorized and the re-rendered form carries that
        // authorization instead of charging again.
        $request->validate([
            'cf-turnstile-response' => ['required', new Turnstile],
        ]);

        try {
            $payment = QuadPayment::verify($request);   // verifies with Payload + captures
        } catch (PaymentBlockedException $e) {
            session()->forget('donation');

            return redirect()->route('donate.step1')->withErrors(['error' => $e->getMessage()]);
        } catch (PaymentVerificationException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        $donation = session('donation');

        $record = Donation::firstOrCreate(
            ['transaction_id' => $payment->transactionId()],
            [
                'name' => $donation['full_name'],
                'email' => $donation['email'],
                'phone' => $donation['phone'],
                'donation_type' => 'General Donation',
                'amount' => $donation['amount'],
                'total_amount_collected' => $payment->amount(),
            ],
        );

        if ($record->wasRecentlyCreated) {
            Mail::to(config('mail.internal_address'))->send(new NewDonation($record));
        }

        session()->forget('donation');

        return view('donate.index', ['step' => 'success', 'donation' => $record, 'total_amount' => $payment->amount()]);
    }
}

/*
 * routes/web.php — validate-attempt and track-attempt routes are no longer needed:
 *
 *   Route::get('/donate', [DonateController::class, 'step1'])->name('donate.step1');
 *   Route::post('/donate', [DonateController::class, 'start'])->name('donate.start');
 *   Route::get('/donate/payment', [DonateController::class, 'step2'])->name('donate.step2');
 *   Route::post('/donate/complete', [DonateController::class, 'complete'])->name('donate.complete');
 *
 * routes/console.php:
 *
 *   Schedule::command('quadpayment:prune')->everyFiveMinutes();
 */
