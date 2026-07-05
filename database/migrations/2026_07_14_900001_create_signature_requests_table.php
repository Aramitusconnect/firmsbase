<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * signature_requests — the firm-owned root of the signature workflow.
 * Exactly one of document_id / generated_document_id is set
 * (source_document_type names which — enforced at the service layer,
 * not a DB constraint, mirroring Phase 10's dual-FK source pattern).
 * status uses the exact 9 master-plan values (see
 * SignatureRequestStatus) and is reused verbatim by
 * signature_request_recipients.status too. attorney_reviewed_at/by/notes
 * is the hard human gate before send() — "E-signature is not a
 * substitute for legal review of whether a specific document can be
 * signed electronically" is enforced here as a required, explicit
 * human sign-off, not a computed flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();

            $table->string('source_document_type');
            $table->foreignId('document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->foreignId('generated_document_id')->nullable()->constrained('generated_documents')->restrictOnDelete();

            $table->string('status')->default('draft');
            $table->string('title');

            $table->foreignId('requested_by_firm_user_id')->constrained('firm_users')->cascadeOnDelete();

            $table->timestamp('attorney_reviewed_at')->nullable();
            $table->foreignId('attorney_reviewed_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->text('attorney_review_notes')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('declined_reason')->nullable();

            $table->timestamps();

            $table->index('firm_id');
            $table->index(['firm_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_requests');
    }
};
