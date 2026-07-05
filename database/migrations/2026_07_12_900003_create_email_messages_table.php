<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * email_messages — one captured message. provider_thread_id lives here
 * (approved correction — no separate email_threads table in Phase 9).
 *
 * storage_mode is the EFFECTIVE mode frozen at capture time (copied
 * from the owning email_account's current storage_mode when the
 * message was captured), not a live reference to the account's current
 * setting — this is deliberate: changing the account's setting later
 * must never reinterpret a message captured under a different mode.
 *
 * When storage_mode was Disabled at capture time, no row exists here
 * at all (approved correction — Disabled blocks capture entirely, so
 * this table never contains a Disabled-mode row; the enum value is
 * retained for completeness/documentation, not because it is ever
 * written here).
 *
 * No plaintext body/body_html/body_text column exists (project rule).
 * encrypted_body_ciphertext + encryption_key_id are the only body
 * storage columns, populated only when storage_mode is EncryptedBody
 * or EncryptedBodyAndAttachments and body_status is Encrypted. When
 * body_status is NotStored or EncryptionFailed, both columns are null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('email_account_id')->constrained('email_accounts')->cascadeOnDelete();

            $table->string('provider_thread_id');
            $table->string('provider_message_id');

            $table->string('direction');
            $table->string('from_address');
            $table->json('to_addresses');
            $table->string('subject')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();

            $table->string('storage_mode');
            $table->string('body_status');
            $table->text('encrypted_body_ciphertext')->nullable();
            $table->foreignId('encryption_key_id')->nullable()->constrained('tenant_encryption_keys')->restrictOnDelete();

            $table->boolean('has_attachments')->default(false);

            $table->timestamps();

            $table->index('firm_id');
            $table->index(['firm_id', 'email_account_id']);
            $table->index(['email_account_id', 'provider_thread_id']);
            $table->unique(['email_account_id', 'provider_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
