<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 9 —
 * "Firm lead queue + lead detail + AI summary." ai_summary is a
 * disposable, regenerable review aid a Firm reviewer can request from
 * the intake-detail page — never authoritative, never shown to the
 * anonymous visitor, and never treated as ground truth for the
 * intake's own structured_data (which stays the single source of
 * record for what was actually answered). Nullable/additive on the
 * same existing marketplace_intakes table — no new RLS design needed,
 * this table is already FORCE RLS with a non-nullable firm_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_intakes', function (Blueprint $table) {
            $table->text('ai_summary')->nullable()->after('conversation_transcript');
            $table->timestamp('ai_summary_generated_at')->nullable()->after('ai_summary');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_intakes', function (Blueprint $table) {
            $table->dropColumn(['ai_summary', 'ai_summary_generated_at']);
        });
    }
};
