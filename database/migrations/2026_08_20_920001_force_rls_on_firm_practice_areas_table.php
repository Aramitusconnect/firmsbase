<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3K — Batched FORCE RLS Rollout 02 (1 of 5). Permanently
 * activates FORCE ROW LEVEL SECURITY for firm_practice_areas.
 *
 * firm_practice_areas is a per-firm enablement join against the global
 * practice_areas catalog (practice_areas has no firm_id and is listed
 * in RowLevelSecurityCoverageMappingService::EXEMPT_TABLES, so it is
 * never itself forced and there is no nested tenant-owned parent whose
 * firm could mismatch). firm_id is NOT NULL and the existing
 * unique(firm_id, practice_area_id) constraint is untouched by this
 * migration. FirmPracticeAreaFactory was given the same context-hold
 * create() override every prior FORCE-RLS factory uses (see the batch
 * report) so a bare FirmPracticeArea::factory()->create() keeps
 * working under FORCE.
 *
 * As established in every prior FORCE batch since Section 39A-3F,
 * PostgreSQL foreign-key constraint checks bypass row level security
 * entirely, so forcing this table does not affect practice_areas or
 * firms inserts/updates themselves.
 *
 * The down() migration restores only the RLS-enabled-but-not-forced
 * baseline for this one table — it never drops the existing policy or
 * disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'firm_practice_areas';

    public function up(): void
    {
        DB::statement('ALTER TABLE '.$this->quoteIdentifier(self::TABLE).' FORCE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE '.$this->quoteIdentifier(self::TABLE).' NO FORCE ROW LEVEL SECURITY');
    }

    private function quoteIdentifier(string $table): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
            throw new \RuntimeException("Refusing to activate FORCE RLS on an unsafe/unexpected identifier: {$table}");
        }

        return '"'.$table.'"';
    }
};
