<?php

namespace QuadCompanies\QuadPayment\Security\Bot;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use QuadCompanies\QuadPayment\Contracts\BotVerifier;

class TurnstileVerifier implements BotVerifier
{
    private const URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(
        private HttpFactory $http,
        private ?string $secret,
        private string $field = 'cf-turnstile-response',
    ) {}

    public function verify(Request $request): bool
    {
        $response = $request->input($this->field);

        if (! $this->secret || ! is_string($response) || $response === '') {
            return false;
        }

        try {
            return (bool) $this->http->asForm()->timeout(10)->post(self::URL, [
                'secret' => $this->secret,
                'response' => $response,
                'remoteip' => $request->ip(),
            ])->json('success', false);
        } catch (\Throwable) {
            // Fail closed: a payment form that can't verify humans shouldn't
            // mint card-testing ammunition.
            return false;
        }
    }

    public function responseField(): ?string
    {
        return $this->field;
    }
}
