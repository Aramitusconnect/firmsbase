<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * generated_documents — firm-owned workflow root. simulated_storage_path
 * mirrors Phase 8 ExportFile / Phase 9 EmailAttachment — a descriptive
 * path string that nothing ever writes to (no real PDF/DOCX binary
 * generation in this phase). used_sample_content (final correction) is
 * set true whenever the document_template_version.content_status was
 * sample_only at generation time, and is re-checked LIVE (not trusted
 * as a snapshot) by DocumentReviewService::approve() — approval throws
 * while the template version is still sample_only, and flips this
 * column to false once approval succeeds against a reviewed_approved
 * version. status uses the exact 8 approved values (GeneratedDocumentStatus),
 * same transition graph as form_drafts via ReviewWorkflowTransitionService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('document_template_version_id')->constrained('document_template_versions')->restrictOnDelete();

            $table->string('status')->default('draft');
            $table->string('simulated_storage_path');
            $table->boolean('used_sample_content')->default(false);

            $table->foreignId('generated_by_firm_user_id')->constrained('firm_users')->cascadeOnDelete();
            $table->foreignId('reviewed_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
    }
};
