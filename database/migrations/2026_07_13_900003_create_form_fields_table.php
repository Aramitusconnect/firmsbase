<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * form_fields — schema for one form_template_version. No uuid — child
 * schema row, never externally referenced on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();

            $table->foreignId('form_template_version_id')->constrained('form_template_versions')->cascadeOnDelete();
            $table->string('field_code');
            $table->string('field_label');
            $table->string('field_type')->default('text');
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('help_text')->nullable();

            $table->timestamps();

            $table->unique(['form_template_version_id', 'field_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_fields');
    }
};
