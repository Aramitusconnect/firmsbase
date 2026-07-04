<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pilot_feedback_items — firm_id/client_id/matter_id/user_id are all
 * nullable: internal-source feedback may not be tied to any firm;
 * firm/client-source feedback links back to whichever of those
 * (and optionally a matter and/or the submitting user) applies. No
 * own uuid — internal triage tool, never client/portal-facing.
 * priority consolidates the master plan's "severity/priority" wording
 * into one enum rather than two near-duplicates (see
 * PilotFeedbackPriority's doc comment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pilot_feedback_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->nullable()->constrained('firms')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('source');
            $table->string('category');
            $table->string('priority')->default('medium');
            $table->string('status')->default('new');

            $table->string('title');
            $table->text('description');
            $table->text('resolution_notes')->nullable();
            $table->boolean('follow_up_required')->default(false);
            $table->timestamp('follow_up_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('status');
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pilot_feedback_items');
    }
};
