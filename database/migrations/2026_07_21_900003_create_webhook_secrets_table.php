<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * webhook_secrets — secret material, no uuid (never externally
 * referenced, mirrors EmailOAuthToken's exact reasoning). Old secrets
 * are rotated, never deleted (correction #8/#13) —
 * encrypted_secret_ciphertext is immutable after creation; only
 * `status`/`rotated_at` ever change on an existing row, and only via
 * WebhookSecretService::rotate() creating a NEW row rather than
 * mutating this one's ciphertext.
 *
 * The partial unique index (Postgres-only syntax, matches this
 * codebase's existing Postgres-first posture) enforces "one active
 * secret per subscription" at the database layer, not just in
 * application code (correction #8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_secrets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('webhook_subscription_id')->constrained('webhook_subscriptions')->cascadeOnDelete();
            $table->text('encrypted_secret_ciphertext');
            $table->foreignId('encryption_key_id')->constrained('tenant_encryption_keys')->cascadeOnDelete();
            $table->string('status');

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('rotated_at')->nullable();

            $table->index(['firm_id', 'webhook_subscription_id']);
        });

        DB::statement(
            "CREATE UNIQUE INDEX webhook_secrets_one_active_per_subscription ".
            "ON webhook_secrets (webhook_subscription_id) WHERE status = 'active'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_secrets');
    }
};
