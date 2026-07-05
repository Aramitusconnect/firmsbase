<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * email_accounts — a connected mailbox (Gmail or Microsoft), firm-
 * scoped. connected_by_firm_user_id is the ONLY actor column (approved
 * decision: mailbox connection is firm-user-only; platform admins
 * cannot connect a lawyer's mailbox in Phase 9 — no
 * connected_by_platform_admin_id column exists at all, unlike Phase
 * 8's api_keys dual-actor pattern).
 *
 * storage_mode is the firm's configured setting for this mailbox
 * (disabled/metadata_only/encrypted_body/encrypted_body_and_
 * attachments — see EmailStorageMode). email_messages.storage_mode
 * separately freezes the EFFECTIVE mode at capture time per message,
 * so changing this setting later never reinterprets already-captured
 * messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('provider');
            $table->string('mailbox_address');
            $table->string('connection_status')->default('pending_authorization');
            $table->string('storage_mode')->default('disabled');

            $table->foreignId('connected_by_firm_user_id')->constrained('firm_users')->cascadeOnDelete();

            $table->timestamp('last_synced_at')->nullable();
            $table->string('error_reason')->nullable();

            $table->timestamps();

            $table->index('firm_id');
            $table->index(['firm_id', 'connection_status']);
            $table->unique(['firm_id', 'provider', 'mailbox_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};
