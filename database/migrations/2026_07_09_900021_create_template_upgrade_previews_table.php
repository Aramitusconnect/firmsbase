<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * template_upgrade_previews — firm_id is NOT NULL (genuinely
 * firm-scoped) — this is one of exactly 3 new Phase 6 tables that gets
 * Phase 6 RLS. Does NOT duplicate template_packs/template_pack_versions/
 * installed_template_packs (approved decision) — it references them by
 * FK and records the DIFF a firm would see before choosing to apply an
 * upgrade via TemplatePackInstallationService::install(). Previewing
 * never mutates installed_template_packs itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_upgrade_previews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('installed_template_pack_id')->constrained('installed_template_packs')->cascadeOnDelete();
            $table->foreignId('from_version_id')->constrained('template_pack_versions');
            $table->foreignId('to_version_id')->constrained('template_pack_versions');

            $table->string('status')->default('generated');
            $table->json('diff_summary_json')->nullable();

            $table->timestamp('previewed_at')->useCurrent();
            $table->foreignId('previewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('installed_template_pack_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_upgrade_previews');
    }
};
