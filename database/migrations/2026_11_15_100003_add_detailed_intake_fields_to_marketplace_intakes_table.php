<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 6 —
 * the Firm-scoped detailed intake experience needs three new columns
 * on the existing marketplace_intakes table:
 *
 * intake_template_id — which deterministic template (checkpoint 3's
 * IntakeTemplateService) this intake is answering. Nullable: set once
 * a template is resolved/attached, not at intake start.
 *
 * conversation_transcript — the RAW AI conversation log, jsonb array
 * of {role, content, at} entries. Deliberately a SEPARATE column from
 * structured_data (which already exists, checkpoint 1) — "AI
 * conversation must not become the source of truth" means the two
 * are never conflated: structured_data holds only validated answers
 * (written exclusively through IntakeTemplateService's own
 * validation, whether the visitor typed them directly or an AI
 * extraction proposed them), conversation_transcript holds the
 * unstructured back-and-forth for context/review only.
 *
 * ai_assisted — whether this intake used the conversational AI-ON
 * path at all (false = purely deterministic questionnaire). A simple,
 * cheap fact worth recording now rather than reconstructing later
 * from conversation_transcript's own presence/absence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_intakes', function (Blueprint $table) {
            $table->foreignId('intake_template_id')->nullable()->after('practice_area_id')->constrained('intake_templates')->nullOnDelete();
            $table->jsonb('conversation_transcript')->nullable()->after('structured_data');
            $table->boolean('ai_assisted')->default(false)->after('conversation_transcript');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_intakes', function (Blueprint $table) {
            $table->dropColumn(['ai_assisted', 'conversation_transcript']);
            $table->dropConstrainedForeignId('intake_template_id');
        });
    }
};
