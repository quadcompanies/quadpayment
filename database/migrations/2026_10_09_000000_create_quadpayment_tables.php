<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No column in these tables holds cardholder data. Cards are referenced by a
 * keyed, irreversible hash of (brand, last4, expiry) for velocity limits only;
 * emails are stored as keyed hashes. Payload holds the actual card.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('quadpayment.connection');
    }

    private function table(string $name): string
    {
        return config('quadpayment.table_prefix', 'qp_') . $name;
    }

    public function up(): void
    {
        Schema::create($this->table('customers'), function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('owner');
            $table->string('payload_account_id', 64)->unique();
            $table->string('email_hash', 64)->nullable()->index();
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id']);
        });

        Schema::create($this->table('checkouts'), function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('purpose', 64)->nullable();
            $table->string('status', 20)->index();
            $table->foreignId('customer_id')->nullable()->index();
            $table->string('payload_account_id', 64)->nullable()->index();
            $table->string('intent_id', 64)->nullable()->index();
            $table->text('client_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->unsignedSmallInteger('tokens_issued')->default(0);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('USD');
            $table->string('description')->nullable();
            $table->string('capture', 10)->default('manual');
            $table->boolean('save_payment_method')->default(false);
            $table->string('ip', 45)->nullable();
            $table->string('subnet', 64)->nullable();
            $table->string('session_hash', 64)->nullable();
            $table->string('email_hash', 64)->nullable();
            $table->unsignedSmallInteger('decline_count')->default(0);
            $table->decimal('decline_score', 6, 2)->default(0);
            $table->string('transaction_id', 64)->nullable()->unique();
            $table->string('payment_method_id', 64)->nullable();
            $table->string('card_brand', 20)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['ip', 'created_at']);
            $table->index(['subnet', 'created_at']);
            $table->index(['email_hash', 'created_at']);
            $table->index(['session_hash', 'created_at']);
            $table->index(['status', 'authorized_at']);
        });

        // Every client token minted. Tokens are what let a browser (or a
        // script) attempt a card, so issuance is rate limited from this table.
        Schema::create($this->table('tokens'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_id')->index();
            $table->string('intent_id', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('subnet', 64)->nullable();
            $table->string('session_hash', 64)->nullable();
            $table->string('email_hash', 64)->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['ip', 'created_at']);
            $table->index(['subnet', 'created_at']);
            $table->index(['session_hash', 'created_at']);
            $table->index(['email_hash', 'created_at']);
        });

        Schema::create($this->table('attempts'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_id')->nullable()->index();
            $table->string('transaction_id', 64)->unique();
            $table->string('payload_account_id', 64)->nullable();
            $table->string('status', 20);
            $table->string('status_code', 40)->nullable();
            $table->decimal('weight', 4, 2)->default(0);
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('card_hash', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('subnet', 64)->nullable();
            $table->string('email_hash', 64)->nullable();
            $table->string('session_hash', 64)->nullable();
            $table->string('source', 12);
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['ip', 'occurred_at']);
            $table->index(['subnet', 'occurred_at']);
            $table->index(['email_hash', 'occurred_at']);
            $table->index(['card_hash', 'occurred_at']);
            $table->index(['session_hash', 'occurred_at']);
            $table->index(['payload_account_id', 'occurred_at']);
        });

        Schema::create($this->table('blocks'), function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);
            $table->string('value', 128);
            $table->string('reason')->nullable();
            $table->unsignedSmallInteger('strikes')->default(1);
            $table->string('source', 10)->default('auto');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['type', 'value']);
        });

        Schema::create($this->table('security_events'), function (Blueprint $table) {
            $table->id();
            $table->string('type', 40)->index();
            $table->string('severity', 10)->default('info');
            $table->foreignId('checkout_id')->nullable()->index();
            $table->string('ip', 45)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->index();
        });

        Schema::create($this->table('settings'), function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create($this->table('webhook_events'), function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->string('trigger', 40)->nullable();
            $table->string('object_id', 64)->nullable();
            $table->string('status', 12);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['webhook_events', 'settings', 'security_events', 'blocks', 'attempts', 'tokens', 'checkouts', 'customers'] as $name) {
            Schema::dropIfExists($this->table($name));
        }
    }
};
