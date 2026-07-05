<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * generated_document_events — pure append-only audit trail, mirrors
 * form_review_events exactly. No BelongsToTenant, no uuid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_document_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('generated_document_id')->constrained('generated_documents')->cascadeOnDelete();
            $table->string('event_type');
            $table->foreignId('actor_firm_user_id')->constrained('firm_users')->cascadeOnDelete();
            $table->text('notes')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['generated_document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_document_events');
    }
};
