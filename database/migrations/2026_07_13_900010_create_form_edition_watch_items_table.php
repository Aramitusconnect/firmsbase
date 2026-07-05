<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * form_edition_watch_items — platform content-ops tracking. No
 * firm_id at all: no firm ever sees or sets this, it is purely
 * internal to FirmsBase's own form-content-ops process.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_edition_watch_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('form_template_id')->constrained('form_templates')->cascadeOnDelete();
            $table->string('watch_status')->default('watching');
            $table->string('detected_edition_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_platform_admin_id')->constrained('platform_admins')->cascadeOnDelete();

            $table->timestamps();

            $table->index(['form_template_id', 'watch_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_edition_watch_items');
    }
};
