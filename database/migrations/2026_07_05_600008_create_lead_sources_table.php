<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * lead_sources — FIRM-SCOPED (approved decision). Each firm manages
 * its own list (e.g. "referral", "google_ads", "walk_in"). No uuid —
 * a small internal lookup list, addressed by code, same pattern as
 * module_catalog/practice_areas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['firm_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_sources');
    }
};
