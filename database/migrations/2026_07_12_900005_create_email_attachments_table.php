<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * email_attachments — metadata only in this phase (no real byte-level
 * fetch — no real Gmail/Graph API call exists). simulated_storage_path
 * mirrors Phase 8's ExportFile.simulated_storage_path pattern: a
 * descriptive path string that nothing ever writes to.
 *
 * document_id is populated ONLY by EmailAttachmentPromotionService,
 * only once promotion_status is Promoted, which itself requires
 * ScanClean AND storage_mode EncryptedBodyAndAttachments on the owning
 * message. Under MetadataOnly or EncryptedBody, an attachment row may
 * exist but can never be promoted — promotion_status stays Pending or
 * moves to Blocked, document_id stays null (approved storage-mode
 * matrix).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('email_message_id')->constrained('email_messages')->cascadeOnDelete();

            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('provider_attachment_id');

            $table->string('scan_status')->default('pending');
            $table->string('simulated_storage_path');

            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('promotion_status')->default('pending');
            $table->string('blocked_reason')->nullable();

            $table->timestamps();

            $table->index('firm_id');
            $table->index(['email_message_id']);
            $table->index('promotion_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_attachments');
    }
};
