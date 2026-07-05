<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * document_template_versions — merge_fields_schema lists placeholder
 * tokens (json); body_template is literal deterministic merge text,
 * never AI-generated. content_status (sample_only/reviewed_approved)
 * mirrors form_mapping_rules' discipline, approved only via
 * DocumentTemplateService::approveContent() — global template content
 * requires a PlatformAdmin actor; firm-specific template content
 * requires a FirmOwner/Attorney of that SAME firm. No AI actor type
 * exists anywhere, so this is structurally never satisfiable by AI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_template_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('document_template_id')->constrained('document_templates')->cascadeOnDelete();
            $table->string('version_label');
            $table->string('status')->default('draft');
            $table->json('merge_fields_schema');
            $table->text('body_template');
            $table->string('content_status')->default('sample_only');

            $table->timestamps();

            $table->unique(['document_template_id', 'version_label']);
            $table->index('content_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_template_versions');
    }
};
