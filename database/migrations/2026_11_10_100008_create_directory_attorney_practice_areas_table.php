<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * directory_attorney_practice_areas — Mission 2 (MyAttorney
 * Marketplace Core), section 10/12. Attorney <-> canonical
 * PracticeArea association. See
 * database/migrations/2026_11_10_100007_create_directory_firm_practice_areas_table.php
 * for the taxonomy-reuse rationale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_attorney_practice_areas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('directory_attorney_id')->constrained('directory_attorneys')->cascadeOnDelete();
            $table->foreignId('practice_area_id')->constrained('practice_areas')->cascadeOnDelete();
            $table->string('source_type');

            $table->timestamps();

            $table->unique(['directory_attorney_id', 'practice_area_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_attorney_practice_areas');
    }
};
