<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * intake_templates — GLOBAL, belongs to a template_pack_version (not
 * to a firm). matter_type_id is nullable: an intake template may apply
 * generically or to one specific matter type within the pack.
 * schema_json holds the actual form field definitions — Phase 2 does
 * not build a form renderer, only the storage shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_templates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('template_pack_version_id')->constrained('template_pack_versions')->cascadeOnDelete();
            $table->foreignId('matter_type_id')->nullable()->constrained('matter_types')->nullOnDelete();

            $table->string('code');
            $table->string('name');
            $table->json('schema_json')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['template_pack_version_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_templates');
    }
};
