<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * template_pack_versions — GLOBAL, one row per released version of a
 * template pack. Matters pin to a specific row here
 * (matters.pinned_template_pack_version_id) at creation time so a
 * later pack upgrade never silently changes an already-open matter
 * (workflow state-machine contract + "Template upgrade" edge case).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_pack_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('template_pack_id')->constrained('template_packs')->cascadeOnDelete();
            $table->string('version');
            $table->string('status')->default('draft');
            $table->text('release_notes')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->unique(['template_pack_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_pack_versions');
    }
};
