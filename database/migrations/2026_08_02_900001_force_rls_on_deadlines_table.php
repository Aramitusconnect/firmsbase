<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3D — fourth batch of the staged "Phase 1 RLS
 * Enforcement Activation" gate (see
 * 2026_07_04_500001_prepare_row_level_security_for_tenant_tables,
 * 2026_07_30_900001_force_rls_on_clients_table,
 * 2026_07_31_900001_force_rls_on_firm_users_table, and
 * 2026_08_01_900001_force_rls_on_documents_table for the full
 * history).
 *
 * deadlines was chosen as the next table after clients, firm_users,
 * and documents following inspection, not by assumption:
 *   - Independent of the client-cascade risk that still makes
 *     matters, invoices, and payments an unknown quantity — their
 *     factories ALWAYS nest a Client::factory() call (even in their
 *     plain, non-forFirm() definition()), so their own INSERT
 *     behavior under FORCE RLS remains unproven.
 *   - deadlines' own factory has no such nesting (matter_id defaults
 *     to null unless explicitly set) and no OTHER factory nests a
 *     Deadline::factory() call either (zero reverse-cascade risk,
 *     confirmed by direct repository search) — the cleanest, most
 *     isolated candidate available.
 *   - Its 8-failure count from the original 52-table discovery run is
 *     real and fully attributable to its own INSERT lacking tenant
 *     context — the smallest, most conservative blast radius of any
 *     remaining prepared table.
 *   - High security value: deadlines holds legal filing/court
 *     deadlines (e.g. immigration case deadlines) — missing or
 *     cross-firm-leaking one has severe real-world consequences.
 *   - Every real production code path that writes/reads deadlines
 *     directly (DeadlineService, FirmCommandCenterAggregationService)
 *     already has a cleanly-known Firm/firm_id available at the call
 *     site — no ambiguous-ownership guessing required.
 *
 * Scope is deliberately a single-table allowlist. The other 48
 * still-unforced prepared tables and the 43 still-uncovered
 * tenant-owned tables are untouched.
 */
return new class extends Migration
{
    private const TABLE = 'deadlines';

    public function up(): void
    {
        DB::statement('ALTER TABLE '.$this->quoteIdentifier(self::TABLE).' FORCE ROW LEVEL SECURITY');
    }

    /**
     * Rollback support: returns deadlines to the Section 39A baseline
     * (RLS enabled, policy present, but NOT forced) — never drops the
     * policy or disables RLS itself, since those are owned by the
     * Phase 4 preparation migration
     * (2026_07_07_800016_extend_row_level_security_to_phase_4_tenant_tables.php).
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
