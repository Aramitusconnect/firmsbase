<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * readiness_score_events — append-only. event_type is a plain string
 * (approved clarification), same treatment as document_chase_events/
 * timeline_events/payment_plan_events/payment_classification_events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('readiness_score_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();

            $table->string('event_type')->default('recomputed');
            $table->string('previous_status')->nullable();
            $table->string('new_status')->nullable();
            $table->json('metadata_json')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['firm_id', 'matter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('readiness_score_events');
    }
};
