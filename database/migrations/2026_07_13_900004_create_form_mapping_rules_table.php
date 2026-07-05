<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * form_mapping_rules — one deterministic rule per field. source_path
 * is a fixed, allowlisted dot-path (see DeterministicFieldResolutionService
 * — never an arbitrary expression). content_status defaults to
 * sample_only; only FormMappingRuleService::approveContent(), which
 * requires a PlatformAdmin actor, may set it to reviewed_approved.
 * approved_by_platform_admin_id is nullable and only ever set at that
 * moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_mapping_rules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('form_template_version_id')->constrained('form_template_versions')->cascadeOnDelete();
            $table->foreignId('form_field_id')->constrained('form_fields')->cascadeOnDelete();

            $table->string('source_entity');
            $table->string('source_path');
            $table->string('transform')->default('none');
            $table->string('content_status')->default('sample_only');

            $table->foreignId('created_by_platform_admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $table->foreignId('approved_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();

            $table->timestamps();

            $table->unique(['form_template_version_id', 'form_field_id']);
            $table->index('content_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_mapping_rules');
    }
};
