<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * documents — private by default, never a public URL (project rule).
 * Representative fields match the master plan PDF's appendix row
 * ("id; firm_id; matter_id; client_id; document_request_item_id;
 * status; storage_path; file_hash; mime_type; size_bytes;
 * encryption_key_id; uploaded_by; approved_by; expires_at") plus the
 * additions this phase's Scope text requires: scan_status (separate
 * from lifecycle status — a document can be Uploaded and still
 * ScanPending at the same time), storage_disk, original_filename, and
 * replaces_document_id (replacement relationships).
 *
 * Files are never stored in this table or any database column — only
 * metadata plus a storage_disk/storage_path pointer (project rule: "Do
 * not store files in the database").
 *
 * Phase 2/3 uploads (if any existed) were development/testing only;
 * this table and DocumentSecurityService/DocumentUploadPolicyService
 * establish the production-safe rules going forward (project rule).
 *
 * Carries a public uuid per approved decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('document_request_item_id')->nullable()->constrained('document_request_items')->nullOnDelete();

            $table->string('status')->default('uploaded');
            $table->string('scan_status')->default('pending');
            $table->text('scan_result_detail')->nullable();
            $table->timestamp('scanned_at')->nullable();

            $table->string('storage_disk');
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('file_hash');

            $table->foreignId('encryption_key_id')->nullable()->constrained('tenant_encryption_keys')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();

            $table->foreignId('replaces_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'matter_id']);
            $table->index('client_id');
            $table->index('document_request_item_id');
            $table->index('status');
            $table->index('scan_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
