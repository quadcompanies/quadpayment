<?php

namespace QuadCompanies\QuadPayment\Security;

use QuadCompanies\QuadPayment\Events\ActorBlocked;
use QuadCompanies\QuadPayment\Models\Block;

/**
 * Temporary and permanent bans on IPs, subnets, email hashes, card hashes,
 * sessions and Payload accounts. Automatic blocks escalate: each repeat
 * offense uses the next duration in guard.block_minutes.
 */
class BlockList
{
    public function __construct(
        private Thresholds $thresholds,
        private SecurityLog $log,
        private Fingerprinter $fingerprinter,
    ) {}

    /** The first active block matching any of the actor's identifiers. */
    public function find(Actor $actor, ?string $cardHash = null): ?Block
    {
        $candidates = array_filter([
            Block::IP => $actor->ip,
            Block::SUBNET => $actor->subnet,
            Block::SESSION => $actor->sessionHash,
            Block::EMAIL => $actor->emailHash,
            Block::ACCOUNT => $actor->accountId,
            Block::CARD => $cardHash,
        ]);

        if (! $candidates) {
            return null;
        }

        return Block::query()
            ->active()
            ->where(function ($query) use ($candidates) {
                foreach ($candidates as $type => $value) {
                    $query->orWhere(fn ($q) => $q->where('type', $type)->where('value', $value));
                }
            })
            ->first();
    }

    public function isBlocked(string $type, string $value): bool
    {
        return Block::query()->active()->where('type', $type)->where('value', $value)->exists();
    }

    /**
     * Block automatically with escalating duration. Re-blocking something
     * that's already actively blocked doesn't add a strike.
     */
    public function escalate(string $type, string $value, string $reason): Block
    {
        $block = Block::query()->firstOrNew(['type' => $type, 'value' => $value]);

        if ($block->exists && $block->isActive()) {
            return $block;
        }

        $strikes = $block->exists ? $block->strikes + 1 : 1;
        $ladder = array_values((array) $this->thresholds->get('block_minutes', [60, 1440, 10080]));
        $minutes = $ladder[min($strikes, count($ladder)) - 1] ?? 60;

        $block->fill([
            'reason' => $reason,
            'strikes' => $strikes,
            'source' => 'auto',
            'expires_at' => now()->addMinutes($minutes),
        ])->save();

        $this->log->record('blocked', 'warning', [
            'type' => $type,
            'value' => $this->displayValue($type, $value),
            'reason' => $reason,
            'strikes' => $strikes,
            'minutes' => $minutes,
        ], ip: in_array($type, [Block::IP], true) ? $value : null);

        event(new ActorBlocked($block));

        return $block;
    }

    /** Manual block. $minutes = null blocks permanently. Emails/cards are hashed. */
    public function block(string $type, string $value, ?int $minutes, string $reason = 'manual'): Block
    {
        $value = $this->normalize($type, $value);

        $block = Block::query()->updateOrCreate(['type' => $type, 'value' => $value], [
            'reason' => $reason,
            'source' => 'manual',
            'expires_at' => $minutes ? now()->addMinutes($minutes) : null,
        ]);

        $this->log->record('blocked', 'warning', ['type' => $type, 'value' => $this->displayValue($type, $value), 'reason' => $reason, 'manual' => true]);

        return $block;
    }

    public function unblock(string $type, string $value): bool
    {
        $deleted = Block::query()->where('type', $type)->where('value', $this->normalize($type, $value))->delete() > 0;

        if ($deleted) {
            $this->log->record('unblocked', 'info', ['type' => $type, 'value' => $this->displayValue($type, $this->normalize($type, $value))]);
        }

        return $deleted;
    }

    private function normalize(string $type, string $value): string
    {
        return match ($type) {
            Block::EMAIL => str_contains($value, '@') ? $this->fingerprinter->emailHash($value) : $value,
            Block::SUBNET => str_contains($value, '/') ? $value : ($this->fingerprinter->subnet($value) ?? $value),
            default => $value,
        };
    }

    private function displayValue(string $type, string $value): string
    {
        return in_array($type, [Block::EMAIL, Block::CARD, Block::SESSION], true) ? substr($value, 0, 12) . '…' : $value;
    }
}
