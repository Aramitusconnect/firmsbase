<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * document_request_items — values taken verbatim from the master plan
 * PDF, Section 33, "Document request item" row for `status`. No own
 * firm_id — scoped transitively through document_request_id, same
 * pattern as invoice_lines. Carries a public uuid per approved
 * decision — the mobile upload flow links to a specific item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_request_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('document_request_id')->constrained('document_requests')->cascadeOnDelete();

            $table->string('label');
            $table->string('status')->default('requested');
            $table->boolean('is_required')->default(true);

            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('waived_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index('document_request_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_request_items');
    }
};
