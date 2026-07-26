<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 of the FirmsVault Platform Admin Control Center mission
 * ("Integration Operations Center"), Integration Overview UI-building
 * pass. Adds `last_successful_sync_at` to
 * `integration_platform_overview_summaries`.
 *
 * WHY THIS COLUMN IS NEEDED (investigation finding, not an oversight):
 * the table already carries `last_sync_outcome`/`last_sync_at`, but
 * those two columns are populated from the MOST RECENT sync run
 * regardless of outcome (see
 * IntegrationPlatformOverviewSummaryService::computeForFirm()'s
 * `$latestSyncRun` query — `orderByDesc('created_at')->first()` with no
 * `status` filter at all). That means `last_sync_at` is honestly "last
 * sync ATTEMPT at", not "last successful sync at" — the Integration
 * Overview page's own new professional label set requires an honest,
 * distinct "Last Successful Sync" metric, and fabricating one by
 * mislabeling the existing attempt-timestamp column would misrepresent
 * a firm's real sync health (e.g. a firm whose most recent 10 attempts
 * all failed would misleadingly show its most recent FAILED attempt's
 * timestamp as if it were a success).
 *
 * This is purely additive: nullable, no default requirement, no
 * backfill (existing rows get a refreshed value on their next
 * IntegrationPlatformOverviewSummaryService::refreshForFirm() call — the
 * same 5-minute-scheduled refresh every other column on this table
 * already relies on; there is no correctness requirement for this
 * column to be populated before that next refresh runs, matching
 * `computed_at`'s own staleness-signal convention for this whole
 * table).
 *
 * No RLS/FORCE RLS change — this table remains fully exempt (see the
 * original create migration's own "WHY THIS TABLE HAS NO RLS AND NO
 * FORCE RLS" docblock, unaffected by this additive column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_platform_overview_summaries', function (Blueprint $table) {
            $table->timestamp('last_successful_sync_at')->nullable()->after('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::table('integration_platform_overview_summaries', function (Blueprint $table) {
            $table->dropColumn('last_successful_sync_at');
        });
    }
};
