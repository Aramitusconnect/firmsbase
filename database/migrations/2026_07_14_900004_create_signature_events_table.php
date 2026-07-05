<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * signature_events — the append-only evidentiary ledger. No uuid, no
 * BelongsToTenant (firm_id is kept for direct queries only, mirroring
 * Phase 10's FormReviewEvent/GeneratedDocumentEvent precedent). No
 * updated_at — the SignatureEvent model blocks any update/delete after
 * creation (see model docblock), the strictest reading of "signature
 * evidence must be immutable or append-only after completion": here it
 * is immutable from creation, not just after completion.
 *
 * acknowledger_type/acknowledger_id/text_version/acknowledged/
 * acknowledged_at are the literal Phase 6 AcknowledgmentRecord field
 * names (snake_case) — populated ONLY on event_type=consent_captured
 * rows, written by SignatureEventLogger from the VO returned by
 * AcknowledgmentSignatureFoundationService::record(). This is the
 * concrete, checkable reuse of the Phase 6 signature-request
 * foundation (see SignatureRecipientWorkflowService::consent()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('signature_request_id')->constrained('signature_requests')->cascadeOnDelete();
            $table->foreignId('signature_request_recipient_id')->nullable()->constrained('signature_request_recipients')->nullOnDelete();

            $table->string('event_type');
            $table->string('actor_type');
            $table->foreignId('actor_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->foreignId('actor_recipient_id')->nullable()->constrained('signature_request_recipients')->nullOnDelete();

            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();

            $table->foreignId('document_hash_id')->nullable()->constrained('document_hashes')->nullOnDelete();

            // Phase 6 AcknowledgmentRecord-compatible fields (consent_captured events only).
            $table->string('acknowledger_type')->nullable();
            $table->unsignedBigInteger('acknowledger_id')->nullable();
            $table->string('text_version')->nullable();
            $table->boolean('acknowledged')->nullable();
            $table->timestamp('acknowledged_at')->nullable();

            $table->json('metadata_json')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('firm_id');
            $table->index(['signature_request_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_events');
    }
};
