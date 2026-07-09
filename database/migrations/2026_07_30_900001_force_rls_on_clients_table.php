<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3A — first batch of the staged "Phase 1 RLS Enforcement
 * Activation" gate named in
 * 2026_07_04_500001_prepare_row_level_security_for_tenant_tables's own
 * docblock.
 *
 * A discovery run (full test suite against a migration that FORCE'd
 * all 52 RowLevelSecurityCoverageMappingService::preparedTables() at
 * once) showed 1168 of 2669 tests failing across 262 test classes —
 * far too large to land, review, or fix safely in a single change.
 * The decision made in response to that discovery was to stage
 * activation table-by-table (or in small verified batches) instead,
 * starting with the table nearly every other tenant-owned factory
 * cascades through: clients (262 of the 1168 discovery failures
 * involved this table alone — the single largest contributor).
 *
 * Scope is deliberately a single-table allowlist, not the full
 * prepared-table list, and not derived from
 * RowLevelSecurityCoverageMappingService (that service's
 * preparedTables() still correctly describes ALL 52 tables that HAVE
 * a policy prepared; this migration activates enforcement for only
 * one of them — the coverage-mapping service is a "what has a
 * policy" registry, not a "what is enforced" registry, and is not
 * modified here).
 *
 * The remaining 51 prepared tables are explicitly NOT touched by this
 * migration and stay pending for later 39A-3 batches (39A-3B, 39A-3C,
 * ...), each following this same discover-then-activate-then-verify
 * pattern.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, so a
 * typo'd or unexpected value fails loudly instead of producing an
 * invalid or unsafe statement.
 */
return new class extends Migration
{
    private const TABLE = 'clients';

    public function up(): void
    {
        DB::statement('ALTER TABLE '.$this->quoteIdentifier(self::TABLE).' FORCE ROW LEVEL SECURITY');
    }

    /**
     * Rollback support: returns clients to the Section 39A baseline
     * (RLS enabled, policy present, but NOT forced) — never drops the
     * policy or disables RLS itself, since those are owned by the
     * Phase 2 preparation migration
     * (2026_07_05_600024_extend_row_level_security_to_phase_2_tenant_tables.php).
     */
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
