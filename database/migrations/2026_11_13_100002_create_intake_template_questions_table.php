<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * intake_template_questions — Mission 3, checkpoint 3. The per-row,
 * ordered, typed question structure intake_templates.schema_json
 * never had (that column stays untouched, reserved for whatever a
 * later checkpoint's conversational/AI-classification layer wants to
 * cache — this table is the ONLY source of truth for deterministic
 * question rendering/validation). GLOBAL, mirrors form_fields'
 * established per-row-under-a-parent-template shape exactly (see
 * that table's own migration) rather than inventing a third pattern:
 * question_code/label/question_type/is_required/sort_order/help_text
 * map directly to form_fields' field_code/field_label/field_type/
 * is_required/sort_order/help_text.
 *
 * options_json is populated only for choice-style question types
 * (Select) — IntakeTemplateService is the sole validator of that
 * shape; the table itself enforces nothing about its contents.
 *
 * depends_on_code/depends_on_equals implement the ONLY form of
 * conditional logic this checkpoint supports, deliberately narrow per
 * the mission's own "conditional logic only where safely supported"
 * instruction: a single equality condition against one other
 * question's answer on the SAME template, evaluated server-side by
 * IntakeTemplateService::validateResponses() — never a boolean
 * expression engine, and never trusted from client-side visibility
 * state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_template_questions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('intake_template_id')->constrained('intake_templates')->cascadeOnDelete();

            $table->string('question_code');
            $table->string('label');
            $table->text('help_text')->nullable();
            $table->string('question_type');
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->jsonb('options_json')->nullable();

            $table->string('depends_on_code')->nullable();
            $table->string('depends_on_equals')->nullable();

            $table->timestamps();

            $table->unique(['intake_template_id', 'question_code']);
            $table->index(['intake_template_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_template_questions');
    }
};
