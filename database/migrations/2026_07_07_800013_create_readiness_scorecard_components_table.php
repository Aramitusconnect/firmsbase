<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * readiness_scorecard_components — GLOBAL platform catalog, no
 * firm_id, same pattern as Phase 2's practice_areas/matter_types. This
 * is the pluggable registry: a component registers here as its module
 * ships (intake_complete/documents_approved/tasks_dependencies_ready/
 * attorney_review_status now; forms_ready in Phase 10;
 * signatures_complete in Phase 11; fees_paid as Phases 3/6 mature) —
 * registering a new component is a data row plus registry code, never
 * a schema change (project rule / acceptance criterion).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('readiness_scorecard_components', function (Blueprint $table) {
            $table->id();

            $table->string('component_key')->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->string('introduced_in_phase')->nullable();
            $table->unsignedInteger('weight')->default(1);

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('readiness_scorecard_components');
    }
};
