<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * form_template_versions — one row per form EDITION. created_by_
 * platform_admin_id is non-nullable and the ONLY actor column — every
 * edition is curated by platform content-ops, never by a firm (USCIS
 * forms are shared global content, not firm data). Retiring a version
 * (status -> retired, retired_at/retired_reason set) is the ONLY write
 * FormTemplateService::retire() performs — it never touches
 * form_drafts, which is what makes historical drafts immune to
 * retirement (project rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_template_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('form_template_id')->constrained('form_templates')->cascadeOnDelete();
            $table->string('edition_date');
            $table->string('status')->default('draft');

            $table->foreignId('created_by_platform_admin_id')->constrained('platform_admins')->cascadeOnDelete();

            $table->timestamp('retired_at')->nullable();
            $table->string('retired_reason')->nullable();

            $table->timestamps();

            $table->unique(['form_template_id', 'edition_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_template_versions');
    }
};
