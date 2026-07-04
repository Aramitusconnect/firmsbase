<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * installed_template_packs — per-firm install record. template_pack_id
 * is denormalized alongside template_pack_version_id specifically to
 * allow a clean unique(firm_id, template_pack_id) constraint — a firm
 * has at most one currently-installed version of any given pack at a
 * time. Upgrading updates template_pack_version_id in place on this
 * row; it never retroactively changes matters.pinned_template_pack_version_id
 * on already-open matters (see template_pack_versions migration
 * comment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installed_template_packs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('template_pack_id')->constrained('template_packs')->cascadeOnDelete();
            $table->foreignId('template_pack_version_id')->constrained('template_pack_versions')->cascadeOnDelete();

            $table->string('status')->default('active');
            $table->timestamp('installed_at')->useCurrent();
            $table->timestamp('disabled_at')->nullable();

            $table->timestamps();

            $table->unique(['firm_id', 'template_pack_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installed_template_packs');
    }
};
