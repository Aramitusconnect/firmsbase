<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * practice_areas — GLOBAL platform catalog (approved decision: no
 * per-firm custom core practice areas in Phase 2). Firms enable/select
 * from this catalog via firm_practice_areas. No uuid — addressed
 * internally by `code`, same pattern as module_catalog. No firm_id —
 * not tenant-owned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_areas', function (Blueprint $table) {
            $table->id();

            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_areas');
    }
};
