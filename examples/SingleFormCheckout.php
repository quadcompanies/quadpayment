<?php

/*
 * One-page form: event registration with other fields plus a card, no prior
 * step. Uses deferred mode (the default): the card fields appear after the
 * visitor passes Turnstile, so loading the page mints nothing.
 *
 * Blade:
 *   <form method="POST" action="{{ route('register.store') }}">
 *       @csrf
 *       <input name="attendee_name" required>
 *       <input name="email" type="email" required>
 *       <select name="ticket">...</select>
 *       <x-quadpayment::card-fields :checkout="$checkout" />
 *       <button>Register & pay</button>
 *   </form>
 */

namespace App\Http\Controllers;

use App\Models\Registration;
use Illuminate\Http\Request;
use QuadCompanies\QuadPayment\Exceptions\PaymentBlockedException;
use QuadCompanies\QuadPayment\Exceptions\PaymentVerificationException;
use QuadCompanies\QuadPayment\Facades\QuadPayment;

class RegistrationController extends Controller
{
    public function create(Request $request)
    {
        $checkout = QuadPayment::checkout('registration')
            ->amount(75)
            ->description('Gala ticket')
            ->create($request);

        return view('register', compact('checkout'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'attendee_name' => 'required|string|max:255',
            'email' => 'required|email',
            'ticket' => 'required|in:standard,vip',
        ]);

        try {
            // Authorize-only so a failure saving our record can release the hold.
            $payment = QuadPayment::verify($request, capture: false);
        } catch (PaymentBlockedException|PaymentVerificationException $e) {
            return back()->withInput()->withErrors(['payment' => $e->getMessage()]);
        }

        try {
            $registration = Registration::create($data + ['transaction_id' => $payment->transactionId()]);
            $payment->capture();
        } catch (\Throwable $e) {
            $payment->void('registration failed: ' . $e->getMessage());
            throw $e;
        }

        return redirect()->route('register.thanks', $registration);
    }
}

/*
 * Saved cards for logged-in users:
 *
 *   // Pay and keep the card on the user's Payload account
 *   QuadPayment::checkout('membership')->amount(20)->for($user)->saveCard()->create($request);
 *
 *   // List saved cards (fetched live from Payload; nothing stored locally)
 *   QuadPayment::customers()->paymentMethods($user);
 *   // => [['id' => 'pm_…', 'brand' => 'visa', 'last4' => '4242', 'expiry' => '12/30', 'holder' => '…']]
 *
 *   // Charge one server-side (ownership is checked against Payload)
 *   QuadPayment::chargeSavedCard($user, $request->input('payment_method_id'),
 *       fn ($c) => $c->amount(20)->description('Monthly membership'));
 *
 *   QuadPayment::customers()->removePaymentMethod($user, $paymentMethodId);
 */
