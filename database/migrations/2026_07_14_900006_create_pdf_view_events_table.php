<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pdf_view_events — append-only. Represents view, download-decision,
 * and (if enabled) annotation events all in ONE table — no
 * pdf_view_sessions or pdf_annotation_events table exists; those were
 * evaluated and found unnecessary (see manifest). annotation_type/
 * annotation_page_number/annotation_content are only ever set when
 * action = annotation_added, and PdfAnnotationService refuses to write
 * that action unless the firm's e_signature entitlement explicitly
 * enables it. DownloadRequested is always logged before
 * PdfDownloadPolicyService decides; the decision itself is a SEPARATE
 * row (download_allowed/download_denied), never applied silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_view_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            $table->string('viewer_type');
            $table->foreignId('viewer_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->foreignId('viewer_recipient_id')->nullable()->constrained('signature_request_recipients')->nullOnDelete();

            $table->string('source_document_type');
            $table->foreignId('document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->foreignId('generated_document_id')->nullable()->constrained('generated_documents')->restrictOnDelete();

            $table->string('action');

            $table->string('annotation_type')->nullable();
            $table->unsignedInteger('annotation_page_number')->nullable();
            $table->text('annotation_content')->nullable();

            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('occurred_at')->useCurrent();

            $table->index('firm_id');
            $table->index(['document_id']);
            $table->index(['generated_document_id']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_view_events');
    }
};
