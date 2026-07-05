<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * form_templates — GLOBAL catalog of supported USCIS form codes (I-130,
 * I-485, I-765, I-864, I-589, N-400, AR-11 to start — see
 * ImmigrationFormCode). No firm_id at all: a form's existence is never
 * firm-specific, mirroring Phase 2's TemplatePack global-catalog
 * pattern. status tracks the form CODE as a whole; each edition's own
 * lifecycle lives in form_template_versions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('form_code')->unique();
            $table->string('form_name');
            $table->string('status')->default('active');

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_templates');
    }
};
