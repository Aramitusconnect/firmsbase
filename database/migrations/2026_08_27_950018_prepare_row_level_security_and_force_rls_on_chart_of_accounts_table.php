<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * chart_of_accounts — a single, independent FORCE ROW LEVEL SECURITY
 * activation checkpoint, the FIRST of a 7-table combined Wave 4
 * accounting/expense-domain batch (chart_of_accounts, expense_categories,
 * expenses, expense_receipts, expense_approvals, accounting_export_batches,
 * accounting_export_lines — see this batch's approved Phase 3 design
 * document for the full cross-table analysis and the reason all 7 land
 * together rather than as independent checkpoints: every one of the 7
 * is read or written inside at least one shared call path, most notably
 * AccountingExportLineBuilderService::buildForBatch(), which touches
 * chart_of_accounts, expenses, and accounting_export_lines together in
 * one call). Like matter_expenses (39A-5, Wave 2) and ai_approval_events/
 * ai_approval_requests (Wave 3) before it, this table has NO pre-existing
 * policy to flip FORCE on for — no ENABLE ROW LEVEL SECURITY and no
 * CREATE POLICY exist for it anywhere yet. This migration does all
 * three steps required by docs/governance/future-table-requirements.md
 * #4/#5 in one batch: ENABLE ROW LEVEL SECURITY, CREATE POLICY, and
 * FORCE ROW LEVEL SECURITY — never leaving RLS-enabled-with-no-policy
 * as an intermediate state. The shared registry
 * (RowLevelSecurityCoverageMappingService, still listing
 * chart_of_accounts under MISSING_PREPARED_TABLES at the point this
 * migration lands on its own) is updated once by the coordinator in a
 * later, separate wave-integration commit — not by this migration.
 *
 * (a) Policy anchor: chart_of_accounts carries its OWN direct, NOT NULL
 * firm_id column (see database/migrations/2026_07_16_900001_
 * create_chart_of_accounts_table.php) — the policy predicate below
 * reads that column directly. No platform-global/default rows exist
 * for this table (confirmed by that migration's own docblock and by no
 * seeder referencing it) — every row is created explicitly through
 * ChartOfAccountsService.
 *
 * (b) Known, deliberately-deferred gaps (not fixed by this migration):
 *   - Actor-attribution: this table has no actor-attribution FK of its
 *     own (unlike several of its sibling tables in this batch).
 *   - FK ON DELETE CASCADE bypasses RLS: firm_id is ->cascadeOnDelete(),
 *     so deleting a firms row will always cascade-delete the matching
 *     chart_of_accounts row regardless of which tenant's context is
 *     currently active — expected, identical behavior to every other
 *     cascade-on-firms table already forced in this repository, not a
 *     gap introduced or left open by this migration.
 *
 * (c) Downstream scope note: expense_categories.chart_of_accounts_id
 * and accounting_export_lines.chart_of_accounts_id both reference this
 * table (both nullable, ->nullOnDelete()). Their own FORCE activation
 * is handled by their own paired migrations landing later in this same
 * batch (2026_08_27_950019 and 2026_08_27_950024 respectively) — this
 * migration makes chart_of_accounts ITSELF safe under FORCE; it does
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
    private const TABLE = 'chart_of_accounts';

    private const POLICY = 'chart_of_accounts_tenant_isolation';

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
