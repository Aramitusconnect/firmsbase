<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * matter_types — GLOBAL platform catalog, scoped under a practice
 * area (e.g. "adjustment_of_status" under "immigration"). No uuid, no
 * firm_id — same reasoning as practice_areas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_types', function (Blueprint $table) {
            $table->id();

            $table->foreignId('practice_area_id')->constrained('practice_areas')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['practice_area_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_types');
    }
};
