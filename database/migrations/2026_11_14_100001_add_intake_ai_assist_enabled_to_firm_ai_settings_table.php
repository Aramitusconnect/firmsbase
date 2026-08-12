<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 4 — a
 * Firm-level toggle reserved for AI-assisted intake features a later
 * checkpoint (6/9) will build. Defaults to false: the deterministic
 * intake path (checkpoint 3) is always fully functional regardless of
 * this flag, and AI-assisted behavior stays opt-in per firm, matching
 * every other opt-in default on this table
 * (full_content_logging_enabled, document_context_enabled,
 * client_data_context_enabled). No AI service reads this column yet —
 * this migration only reserves it, the same "small integration seam,
 * no behavior" shape used elsewhere in this mission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firm_ai_settings', function (Blueprint $table) {
            $table->boolean('intake_ai_assist_enabled')->default(false)->after('full_content_logging_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('firm_ai_settings', function (Blueprint $table) {
            $table->dropColumn('intake_ai_assist_enabled');
        });
    }
};
