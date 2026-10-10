<?php

namespace QuadCompanies\QuadPayment\Console;

use Illuminate\Console\Command;
use QuadCompanies\QuadPayment\Models\Block;
use QuadCompanies\QuadPayment\Security\BlockList;

class BlockCommand extends Command
{
    protected $signature = 'quadpayment:block
        {action=list : list | add | remove}
        {type? : ip | subnet | email | card | session | account}
        {value? : IP, CIDR, email address (hashed automatically) or hash}
        {--minutes= : Duration; omit for a permanent block}
        {--reason=manual}';

    protected $description = 'List, add or remove payment blocks';

    public function handle(BlockList $blocks): int
    {
        $action = $this->argument('action');

        if ($action === 'list') {
            $rows = Block::query()->active()->orderByDesc('updated_at')->limit(200)->get()
                ->map(fn (Block $b) => [
                    $b->type,
                    in_array($b->type, [Block::EMAIL, Block::CARD, Block::SESSION], true) ? substr($b->value, 0, 16) . '…' : $b->value,
                    $b->strikes,
                    $b->source,
                    $b->expires_at?->toDateTimeString() ?? 'permanent',
                    str($b->reason)->limit(60),
                ]);

            $this->table(['Type', 'Value', 'Strikes', 'Source', 'Expires', 'Reason'], $rows);

            return self::SUCCESS;
        }

        $type = $this->argument('type');
        $value = $this->argument('value');

        if (! in_array($type, [Block::IP, Block::SUBNET, Block::EMAIL, Block::CARD, Block::SESSION, Block::ACCOUNT], true) || ! $value) {
            $this->error('Usage: quadpayment:block add|remove <ip|subnet|email|card|session|account> <value>');

            return self::INVALID;
        }

        if ($action === 'add') {
            $minutes = $this->option('minutes') !== null ? (int) $this->option('minutes') : null;
            $blocks->block($type, $value, $minutes, $this->option('reason'));
            $this->info("Blocked {$type} " . ($minutes ? "for {$minutes} minutes." : 'permanently.'));

            return self::SUCCESS;
        }

        if ($action === 'remove') {
            $blocks->unblock($type, $value)
                ? $this->info("Unblocked {$type}.")
                : $this->warn('No such block.');

            return self::SUCCESS;
        }

        $this->error('Unknown action.');

        return self::INVALID;
    }
}
