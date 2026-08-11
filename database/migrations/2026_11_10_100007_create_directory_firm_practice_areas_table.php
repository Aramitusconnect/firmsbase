<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * directory_firm_practice_areas — Mission 2 (MyAttorney Marketplace
 * Core), section 8/12. Firm <-> canonical PracticeArea association.
 * Deliberately a separate table from the existing `firm_practice_areas`
 * (tenant-firm <-> catalog enablement) — a directory_firms row may
 * have no linked tenant Firm at all (section 6), so this cannot be
 * expressed through the tenant-owned join table. Both point at the
 * same canonical `practice_areas` catalog (section 12's single source
 * of truth), never a duplicate taxonomy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_firm_practice_areas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('directory_firm_id')->constrained('directory_firms')->cascadeOnDelete();
            $table->foreignId('practice_area_id')->constrained('practice_areas')->cascadeOnDelete();
            $table->string('source_type');

            $table->timestamps();

            $table->unique(['directory_firm_id', 'practice_area_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_firm_practice_areas');
    }
};
