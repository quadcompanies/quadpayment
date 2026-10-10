<?php

namespace QuadCompanies\QuadPayment\Contracts;

use Illuminate\Http\Request;

/**
 * Challenge that must pass before a client token is minted. Bind your own
 * implementation (hCaptcha, reCAPTCHA...) to replace Turnstile.
 */
interface BotVerifier
{
    public function verify(Request $request): bool;

    /** Request field the browser sends the challenge response in. */
    public function responseField(): ?string;
}
