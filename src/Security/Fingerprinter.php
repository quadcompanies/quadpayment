<?php

namespace QuadCompanies\QuadPayment\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Turns request and card attributes into stable, non-reversible identifiers.
 * Everything sensitive is HMAC'd with the application key, so the hashes are
 * useless outside this app and a database leak reveals no emails or cards.
 */
class Fingerprinter
{
    private const SESSION_KEY = 'quadpayment.sid';

    public function __construct(
        private string $key,
        private int $ipv4Prefix = 24,
        private int $ipv6Prefix = 64,
    ) {}

    public function actor(Request $request, ?string $email = null, ?string $accountId = null): Actor
    {
        $ip = $request->ip();

        return new Actor(
            $ip,
            $this->subnet($ip),
            $this->sessionHash($request),
            $email ? $this->emailHash($email) : null,
            $accountId,
        );
    }

    /**
     * A random id kept in the Laravel session — survives login session id
     * rotation, so "per session" limits can't be reset by logging in.
     */
    public function sessionHash(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $session = $request->session();

        if (! $session->has(self::SESSION_KEY)) {
            $session->put(self::SESSION_KEY, Str::random(40));
        }

        return $this->hash('session', $session->get(self::SESSION_KEY));
    }

    public function subnet(?string $ip): ?string
    {
        if (! $ip) {
            return null;
        }

        $packed = @inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        $prefix = strlen($packed) === 4 ? $this->ipv4Prefix : $this->ipv6Prefix;
        $bytes = intdiv($prefix, 8);
        $masked = substr($packed, 0, $bytes) . str_repeat("\0", strlen($packed) - $bytes);

        return inet_ntop($masked) . '/' . $prefix;
    }

    public function emailHash(string $email): string
    {
        return $this->hash('email', $this->normalizeEmail($email));
    }

    /**
     * Fold the tricks used to mint "new" addresses for the same inbox:
     * case, plus-tags, and Gmail's ignored dots.
     */
    public function normalizeEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', strtolower(trim($email)), 2), 2, '');
        $local = preg_replace('/\+.*$/', '', $local);

        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local . '@' . $domain;
    }

    /**
     * Identifies "the same card" across attempts from the details Payload
     * exposes after the fact (brand, last 4, expiry). Collisions between
     * different cards are possible but rare enough for velocity limits.
     */
    public function cardHash(?string $brand, ?string $last4, ?string $expiry): ?string
    {
        if (! $last4) {
            return null;
        }

        $expiry = preg_replace('/\D/', '', (string) $expiry);

        return $this->hash('card', strtolower((string) $brand) . '|' . $last4 . '|' . $expiry);
    }

    public function hash(string $purpose, string $value): string
    {
        return hash_hmac('sha256', $purpose . ':' . $value, $this->key);
    }
}
