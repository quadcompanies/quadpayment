<?php

namespace QuadCompanies\QuadPayment\Security\Bot;

use Illuminate\Http\Request;
use QuadCompanies\QuadPayment\Contracts\BotVerifier;

/** No challenge. For local development and tests only. */
class NullVerifier implements BotVerifier
{
    public function verify(Request $request): bool
    {
        return true;
    }

    public function responseField(): ?string
    {
        return null;
    }
}
