<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_approval_requests — approved decision #4: stores an ENCRYPTED
 * content snapshot of the pending AI draft under review, using the
 * exact same tenant-encryption chain as webhook secrets/email bodies
 * (EncryptionKeyService + EmailBodyEncryptionService — no second
 * encryption system). This is allowed even when
 * firm_ai_settings.full_content_logging_enabled is false, because
 * approval needs a stable review artifact — but the RAW provider
 * prompt/response is never stored here or anywhere else unless that
 * policy flag is true (that distinction is enforced in
 * AiApprovalWorkflowService/AiUsageRecorderService, not in this
 * migration).
 *
 * draft_label is a fixed, non-configurable value
 * ('ai_generated_draft') written by AiApprovalWorkflowService on every
 * insert — project rule 21 requires every AI output to be labeled as
 * an AI-generated draft, so this column exists to make that label a
 * stored, queryable fact rather than something the UI layer might
 * forget to render.
 *
 * category is restricted at the application layer to
 * AiApprovalCategory's six values — every row in this table is, by
 * construction, a high-risk request (project rule 15/19/20: only
 * high-risk/client-facing output ever needs approval in Phase 15).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ai_usage_event_id')->constrained('ai_usage_events')->cascadeOnDelete();

            $table->string('category');
            $table->string('status')->default('pending');
            $table->string('draft_label')->default('ai_generated_draft');

            $table->text('encrypted_snapshot_ciphertext');
            $table->foreignId('encryption_key_id')->constrained('tenant_encryption_keys')->cascadeOnDelete();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();

            $table->index(['firm_id', 'status']);
            $table->index(['firm_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_approval_requests');
    }
};
