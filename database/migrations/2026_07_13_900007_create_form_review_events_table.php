<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * form_review_events — pure append-only audit trail. Carries firm_id
 * for direct firm-scoped queries but deliberately does NOT use
 * BelongsToTenant (Phase 8/9 audit-row precedent). No uuid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_review_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('form_draft_id')->constrained('form_drafts')->cascadeOnDelete();
            $table->string('event_type');
            $table->foreignId('actor_firm_user_id')->constrained('firm_users')->cascadeOnDelete();
            $table->text('notes')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['form_draft_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_review_events');
    }
};
