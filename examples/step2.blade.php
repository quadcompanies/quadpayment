{{--
    The original step2.blade.php rewritten on top of quadpayment: ~200 lines of
    Payload.js wiring become one component inside an ordinary form.
--}}
<div class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden p-8 lg:p-10">
  <div class="px-8 py-5 flex items-center justify-between rounded-xl" style="background:linear-gradient(135deg,#0d1630,#1a2f5e)">
    <div>
      <p class="text-white/45 text-xs font-body">Donating</p>
      <p class="font-display text-2xl text-white font-light"><em class="text-blush-300">${{ $display_amount }}</em></p>
    </div>
    <div class="text-right">
      <p class="text-white/45 text-xs font-body">{{ $donation['full_name'] }}</p>
      <p class="text-white/45 text-xs font-body">{{ $donation['email'] }}</p>
    </div>
  </div>

  @if($errors->any())
    <div class="rounded-xl px-4 py-3 my-6 bg-red-50 border border-red-200 text-xs text-red-700">{{ $errors->first() }}</div>
  @endif

  <form method="POST" action="{{ route('donate.complete') }}" class="my-6">
    @csrf

    {{-- Step 1 already passed Turnstile, so the token can be minted on render. --}}
    <x-quadpayment::card-fields
        :checkout="$checkout"
        mode="eager"
        input-class="border-2 border-gray-200 rounded-xl p-3 w-full transition-colors focus-within:border-navy-500"
        label-class="block text-sm font-semibold text-navy-700 mb-2 font-body"
        group-class="mb-4"
        :default-styles="false" />

    <div class="mb-5"><x-turnstile /></div>

    <div class="rounded-xl px-4 py-3 mb-6 text-xs font-body text-navy-600/50" style="background:#f7f9ff;border:1px solid #e2e5f0">
      Your donation goes directly to SGB's charitable partners. You'll receive a tax receipt at
      <strong class="text-navy-700">{{ $donation['email'] }}</strong>.
    </div>

    <div class="flex gap-3">
      <a href="{{ route('donate.step1') }}" class="px-5 py-4 rounded-full border-2 border-gray-200 text-sm font-semibold text-navy-700">Back</a>
      <button type="submit" class="btn-primary flex-1 py-4 rounded-full font-semibold text-white">Complete Donation</button>
    </div>
  </form>
</div>
