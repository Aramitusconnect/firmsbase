<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * firm_ai_provider_keys — secret material, no uuid (never externally
 * referenced, mirrors webhook_secrets/email_oauth_tokens' exact
 * reasoning). Old keys are rotated, never deleted (project rule 6) —
 * encrypted_key_ciphertext is immutable after creation; only
 * `status`/`rotated_at` ever change on an existing row, and only via
 * AiProviderKeyService::rotate() creating a NEW row rather than
 * mutating this one's ciphertext.
 *
 * The partial unique index (Postgres-only syntax, matches
 * webhook_secrets_one_active_per_subscription exactly) enforces "one
 * active key per firm per provider" at the database layer (project
 * rule 7), not just in application code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_ai_provider_keys', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('provider');
            $table->text('encrypted_key_ciphertext');
            $table->foreignId('encryption_key_id')->constrained('tenant_encryption_keys')->cascadeOnDelete();
            $table->string('status');
            $table->string('label')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('rotated_at')->nullable();

            $table->index(['firm_id', 'provider']);
        });

        DB::statement(
            "CREATE UNIQUE INDEX firm_ai_provider_keys_one_active_per_firm_provider ".
            "ON firm_ai_provider_keys (firm_id, provider) WHERE status = 'active'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_ai_provider_keys');
    }
};
