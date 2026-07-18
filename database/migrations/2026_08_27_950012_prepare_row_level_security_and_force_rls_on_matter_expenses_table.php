<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * matter_expenses — a single, independent FORCE ROW LEVEL SECURITY
 * activation checkpoint drawn from RowLevelSecurityCoverageMappingService::
 * missingPreparedTables() (Section 39A-4A.1 inventory sweep). Like
 * ai_retrieval_indexes/deployment_configs/firm_ai_settings (39A-5, Wave
 * 1) and customer_success_health_scores before it, this table has NO
 * pre-existing policy to flip FORCE on for — no ENABLE ROW LEVEL
 * SECURITY and no CREATE POLICY exist for it anywhere yet. This
 * migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state. The shared registry
 * (RowLevelSecurityCoverageMappingService, still listing
 * matter_expenses under MISSING_PREPARED_TABLES at the point this
 * migration lands on its own) is updated once by the coordinator in a
 * later, separate wave-integration commit — not by this migration.
 *
 * (a) Policy anchor: matter_expenses carries its OWN direct, NOT NULL
 * firm_id column (see database/migrations/2026_07_16_900006_
 * create_matter_expenses_table.php) — the policy predicate below reads
 * that column directly, exactly like every other DirectTenant table's
 * policy in this registry. It does not need to look through matter_id
 * or expense_id to find a firm.
 *
 * (b) Known, deliberately-deferred gap: no composite foreign key or
 * trigger ties matter_expenses.firm_id to the ACTUAL firm_id of the row
 * matter_id/expense_id point at. PostgreSQL RLS on matter_expenses
 * alone cannot see into the matters/expenses tables to cross-check
 * this — that would require a structurally different EXISTS-against-
 * parent policy (a separate, unaddressed architectural question, per
 * this registry's own scope boundary note), not the standard
 * `firm_id = current_setting(...)` template used here. Today, the ONLY
 * thing preventing a matter_expenses row from pointing at a matter or
 * expense belonging to a different firm than its own firm_id is
 * MatterExpenseService::link()'s inline PHP checks (the `$matter->
 * firm_id !== $firm->id` guard and
 * TenantSafeAccountingPolicyService::assertMatterAndExpenseShareFirm()).
 * This migration does not close that gap — it is stated here, not
 * hidden.
 *
 * (c) Parent table scope: `expenses` (matter_expenses.expense_id's
 * target table) remains UNPREPARED — still listed in
 * RowLevelSecurityCoverageMappingService::MISSING_PREPARED_TABLES, with
 * no RLS policy of any kind. This migration makes matter_expenses ITSELF
 * safe under FORCE; it does not make expenses safe, and a caller reading
 * expenses directly still has zero RLS protection regardless of this
 * migration landing.
 *
 * Policy shape: a single policy with an EXPLICIT WITH CHECK clause
 * (identical to the USING expression), matching the explicit-over-
 * implicit convention established by customer_success_health_scores and
 * the 39A-5 Wave 1 tables, rather than relying on Postgres's "USING
 * doubles as WITH CHECK when none is given" behavior used by the
 * earlier 39A-3 arc.
 *
 * Known, accepted, non-gap behavior: PostgreSQL's documented row-
 * security semantics exempt foreign-key ON DELETE CASCADE actions from
 * row-security policy evaluation entirely. matter_expenses.firm_id,
 * matter_id, and expense_id are all ->cascadeOnDelete(), so deleting a
 * firms/matters/expenses row will always cascade-delete the matching
 * matter_expenses row regardless of which tenant's context is currently
 * active — expected, identical behavior to every other cascade-on-
 * firms table already forced in this repository, not a gap introduced
 * or left open by this migration.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'matter_expenses';

    private const POLICY = 'matter_expenses_tenant_isolation';

    public function up(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);
        $policy = $this->quoteIdentifier(self::POLICY);

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");

        DB::statement(<<<SQL
            CREATE POLICY {$policy}
            ON {$table}
            USING (
                firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
            )
            WITH CHECK (
                firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
            )
        SQL);

        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }

    /**
     * Full rollback: this migration introduced the policy itself (there
     * was no pre-existing policy to merely un-FORCE), so down() must
     * remove all three effects up() added: FORCE, the policy, and row-
     * level security being enabled at all — restoring the table to its
     * true pre-this-migration (MISSING_PREPARED_TABLES) state.
     */
    public function down(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);
        $policy = $this->quoteIdentifier(self::POLICY);

        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY {$policy} ON {$table}");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)) {
            throw new RuntimeException("Refusing to operate on an unsafe/unexpected identifier: {$identifier}");
        }

        return '"'.$identifier.'"';
    }
};
