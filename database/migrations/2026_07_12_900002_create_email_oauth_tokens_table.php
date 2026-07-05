<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * email_oauth_tokens — OAuth token material for a connected mailbox.
 * No firm_id column — scoped transitively through email_account_id
 * (same reasoning as Phase 8's import_rows having no firm_id of its
 * own). No uuid — secret material, never referenced externally by any
 * API/route.
 *
 * encrypted_token_ciphertext is the ONLY place token material is
 * stored — there is no plaintext access_token/refresh_token column
 * anywhere on this table (project rule). Encryption uses the firm's
 * EXISTING per-firm TenantEncryptionKey (Phase 1 EncryptionKeyService)
 * — encryption_key_id points at the exact key version used, so a key
 * rotation never breaks decryption of tokens encrypted under a prior
 * version. If a firm has no active TenantEncryptionKey at store time,
 * EmailOAuthTokenService throws rather than persisting anything here
 * (fail closed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_oauth_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('email_account_id')->constrained('email_accounts')->cascadeOnDelete();
            $table->string('token_type');
            $table->text('encrypted_token_ciphertext');
            $table->foreignId('encryption_key_id')->constrained('tenant_encryption_keys')->restrictOnDelete();
            $table->string('status')->default('active');
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index('email_account_id');
            $table->index(['email_account_id', 'token_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_oauth_tokens');
    }
};
