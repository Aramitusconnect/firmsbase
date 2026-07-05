<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * signature_request_recipients — one row per signer. signer_email is
 * the always-required fallback; client_id/contact_id/party_id/
 * recipient_firm_user_id are nullable linked-record references used
 * where available (recipient_type names which, if any). status reuses
 * SignatureRequestStatus verbatim (see that enum's docblock).
 * text_version/consented_at are a fast-lookup CACHE of the
 * Phase-6-compatible consent evidence — the AUTHORITATIVE, immutable
 * record is the ConsentCaptured signature_events row (see
 * SignatureEventLogger). access_token_hash is a hash only — no
 * plaintext signing-link secret is ever stored, and no delivery
 * mechanism for it is built in this phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_request_recipients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('signature_request_id')->constrained('signature_requests')->cascadeOnDelete();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            $table->string('recipient_type');
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->foreignId('recipient_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();

            $table->string('signer_name')->nullable();
            $table->string('signer_email');

            $table->string('status')->default('draft');
            $table->string('text_version')->nullable();

            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('declined_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('voided_at')->nullable();

            $table->string('access_token_hash')->nullable();

            $table->timestamps();

            $table->index('firm_id');
            $table->index(['signature_request_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_request_recipients');
    }
};
