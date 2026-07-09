<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3B — second batch of the staged "Phase 1 RLS Enforcement
 * Activation" gate (see 2026_07_04_500001_prepare_row_level_security_for_tenant_tables
 * and 2026_07_30_900001_force_rls_on_clients_table for the full history).
 *
 * firm_users was chosen as the next table after clients following
 * inspection, not by assumption:
 *   - Independent of the client-cascade risk that makes matters,
 *     invoices, and payments an unknown quantity right now — their own
 *     factories ALWAYS nest a Client::factory() call (even in their
 *     plain, non-forFirm() definition()), so the original 52-table
 *     discovery run's "zero failures" reading for those three tables
 *     was an artifact of the client insert failing first and aborting
 *     the transaction before their own insert was ever reached, not
 *     genuine evidence of safety.
 *   - firm_users' own factory has no such nesting (just User::factory()
 *     and Firm::factory()), so its 103-failure count from that same
 *     discovery run is real and fully attributable to its own INSERT
 *     lacking tenant context — an already-understood, fixable blast
 *     radius using the exact pattern proven for clients.
 *   - High security value: firm_users is the firm-membership/
 *     authorization boundary — arguably as security-critical as
 *     clients itself (WHO can act as a firm member, vs. WHOSE data it
 *     is).
 *
 * Scope is deliberately a single-table allowlist. The other 50
 * still-unforced prepared tables (51 minus clients) and the 43
 * still-uncovered tenant-owned tables are untouched.
 */
return new class extends Migration
{
    private const TABLE = 'firm_users';

    public function up(): void
    {
        DB::statement('ALTER TABLE '.$this->quoteIdentifier(self::TABLE).' FORCE ROW LEVEL SECURITY');
    }

    /**
     * Rollback support: returns firm_users to the Section 39A baseline
     * (RLS enabled, policy present, but NOT forced) — never drops the
     * policy or disables RLS itself, since those are owned by the
     * Phase 1 preparation migration
     * (2026_07_04_500001_prepare_row_level_security_for_tenant_tables.php).
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
