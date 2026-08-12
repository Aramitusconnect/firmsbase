<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 3 —
 * extends the EXISTING intake_templates table (Phase 2, GLOBAL
 * reference catalog) rather than building a competing form engine.
 *
 * intake_templates.template_pack_version_id was NOT NULL from its own
 * create-table migration — every prior row exists to back a Firm's
 * installed template pack (an onboarded-Firm, matter-workflow
 * concept). A MyAttorney marketplace intake template is a genuinely
 * different, PRE-Firm-relationship thing: a platform-wide,
 * practice-area-driven default form a public visitor fills out before
 * any Firm has installed anything. Relaxing this column to nullable
 * is the minimal, disclosed, backward-compatible way to let both
 * kinds of IntakeTemplate row coexist in the same table — every
 * existing row keeps its real (non-null) value; only a new
 * marketplace-default row may now have none. Mirrors matter_type_id's
 * own already-nullable "generic vs specific" shape on this same
 * table exactly.
 *
 * doctrine/dbal is not installed in this codebase (confirmed —
 * ->nullable()->change() is unavailable), so this uses raw SQL,
 * matching the established convention for schema alterations here.
 *
 * practice_area_id is a new, DIRECT link to practice_areas — distinct
 * from the existing indirect matter_type_id -> matterType.practiceArea
 * path, because a marketplace visitor selects a general practice area
 * before any specific MatterType is known. Nullable: a null value is
 * the platform-wide generic/default template (no practice-area
 * specialization).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE intake_templates ALTER COLUMN template_pack_version_id DROP NOT NULL');

        Schema::table('intake_templates', function (Blueprint $table) {
            $table->foreignId('practice_area_id')->nullable()->after('template_pack_version_id')->constrained('practice_areas')->nullOnDelete();
            $table->index('practice_area_id');
        });
    }

    public function down(): void
    {
        Schema::table('intake_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('practice_area_id');
        });

        DB::statement('ALTER TABLE intake_templates ALTER COLUMN template_pack_version_id SET NOT NULL');
    }
};
