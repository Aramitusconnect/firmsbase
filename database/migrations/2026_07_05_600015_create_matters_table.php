<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * matters — primary_practice_area_id: exactly one primary practice
 * area per matter (project rule) — a firm may enable many areas
 * (firm_practice_areas), but each matter still picks exactly one here.
 *
 * pinned_template_pack_version_id: set once at matter creation and
 * never changed by a later pack upgrade (workflow state-machine
 * contract + "Template upgrade" edge case). Nullable because a matter
 * can in principle exist under a practice area with no installed
 * template pack yet.
 *
 * status is the canonical workflow state machine (App\Enums\MatterStatus).
 * stage is a separate, deliberately freeform nullable string — Phase 2
 * does not build a rigid stage state machine, since practice-area
 * templates (not platform code) are meant to define stage progressions
 * (project rule: "Immigration-specific logic must live in templates,
 * not hardcoded matter rules").
 *
 * No billing_status, no readiness_score columns — approved decision;
 * those belong to Phase 3 and Phase 4 respectively and will be added
 * by those phases via expand migrations when their owning systems
 * actually exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matters', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('primary_practice_area_id')->constrained('practice_areas')->restrictOnDelete();
            $table->foreignId('matter_type_id')->constrained('matter_types')->restrictOnDelete();
            $table->foreignId('pinned_template_pack_version_id')
                ->nullable()
                ->constrained('template_pack_versions')
                ->nullOnDelete();

            $table->string('status')->default('draft');
            $table->string('stage')->nullable();

            $table->foreignId('assigned_attorney_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('client_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matters');
    }
};
