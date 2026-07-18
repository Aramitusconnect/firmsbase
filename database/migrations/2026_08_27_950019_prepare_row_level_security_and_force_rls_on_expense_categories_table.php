<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * expense_categories — the SECOND checkpoint of this batch's 7-table
 * combined Wave 4 accounting/expense-domain activation (see
 * 2026_08_27_950018's docblock for the full combined-batch rationale).
 * Like its sibling tables in this batch, expense_categories has NO
 * pre-existing policy to flip FORCE on for — no ENABLE ROW LEVEL
 * SECURITY and no CREATE POLICY exist for it anywhere yet. This
 * migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state. The shared registry
 * (RowLevelSecurityCoverageMappingService) is updated once by the
 * coordinator in a later, separate wave-integration commit — not by
 * this migration.
 *
 * (a) Policy anchor: expense_categories carries its OWN direct, NOT
 * NULL firm_id column (see database/migrations/2026_07_16_900002_
 * create_expense_categories_table.php) — the policy predicate below
 * reads that column directly. It does not need to look through
 * chart_of_accounts_id to find a firm. No platform-global/default
 * categories exist for this table (correction #3) — every row is
 * created explicitly through ExpenseCategoryService.
 *
 * (b) Known, deliberately-deferred gaps (not fixed by this migration):
 *   - Single-hop cross-firm-mismatch: no composite foreign key or
 *     trigger ties expense_categories.firm_id to the ACTUAL firm_id of
 *     the chart_of_accounts row chart_of_accounts_id points at.
 *     PostgreSQL RLS on expense_categories alone cannot see into
 *     chart_of_accounts to cross-check this — that would require a
 *     structurally different EXISTS-against-parent policy, not the
 *     standard `firm_id = current_setting(...)` template used here.
 *     Today, the ONLY thing preventing an expense_categories row from
 *     pointing at a chart_of_accounts row belonging to a different
 *     firm is ExpenseCategoryService::create()'s/mapToChartOfAccount()'s
 *     own TenantSafeAccountingPolicyService::assertChartOfAccountBelongsToFirm()
 *     call. This migration does not close that gap — it is stated
 *     here, not hidden.
 *   - FK ON DELETE CASCADE/SET NULL bypasses RLS: firm_id is
 *     ->cascadeOnDelete() and chart_of_accounts_id is ->nullOnDelete(),
 *     so deleting a firms/chart_of_accounts row will always
 *     cascade/null the matching expense_categories row(s) regardless
 *     of which tenant's context is currently active — expected,
 *     identical behavior to every other cascade-on-firms table already
 *     forced in this repository, not a gap introduced or left open by
 *     this migration.
 *
 * (c) Parent/dependent table scope: chart_of_accounts' own FORCE state
 * is handled by the paired migration (2026_08_27_950018) landing
 * earlier in this same batch. `expenses` (expense_category_id's
 * dependent table) is handled by 2026_08_27_950020, landing next. This
 * migration makes expense_categories ITSELF safe under FORCE; it does
 * not itself alter either of those tables.
 *
 * Policy shape: a single policy with an EXPLICIT WITH CHECK clause
 * (identical to the USING expression), matching the explicit-over-
 * implicit convention established since customer_success_health_scores.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'expense_categories';

    private const POLICY = 'expense_categories_tenant_isolation';

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
