<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * template_packs — GLOBAL catalog of installable practice-area packs
 * (immigration is the first). No uuid, no firm_id — a pack is platform
 * reference data; firms install a specific VERSION of it via
 * installed_template_packs. practice_area_id is nullable: a pack could
 * in principle be practice-area-agnostic, though the immigration
 * starter pack will set it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_packs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('practice_area_id')->nullable()->constrained('practice_areas')->nullOnDelete();
            $table->string('pack_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('draft');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_packs');
    }
};
