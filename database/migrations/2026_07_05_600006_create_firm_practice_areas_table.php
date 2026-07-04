<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * firm_practice_areas — per-firm enablement join, mirrors the
 * firm_entitlements pattern from Phase 1 exactly: practice_areas
 * itself stays a global catalog, this table is the only place a firm's
 * "which areas can I use" decision lives. A firm may enable multiple
 * areas; each matter still has exactly one primary practice area
 * (enforced at the matters level, not here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_practice_areas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('practice_area_id')->constrained('practice_areas')->cascadeOnDelete();

            $table->boolean('is_enabled')->default(true);
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();

            $table->timestamps();

            $table->unique(['firm_id', 'practice_area_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_practice_areas');
    }
};
