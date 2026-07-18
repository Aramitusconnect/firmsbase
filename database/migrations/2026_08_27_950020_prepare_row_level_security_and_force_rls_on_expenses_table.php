<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * expenses — the THIRD checkpoint of this batch's 7-table combined
 * Wave 4 accounting/expense-domain activation (see 2026_08_27_950018's
 * docblock for the full combined-batch rationale), and the domain's
 * central hub table: 3 of its 6 in-scope siblings (expense_receipts,
 * expense_approvals, accounting_export_lines) and 2 already-forced,
 * out-of-scope tables (matter_expenses, invoice_lines) all hold a
 * foreign key into it. Like its sibling tables in this batch, expenses
 * has NO pre-existing policy to flip FORCE on for — no ENABLE ROW
 * LEVEL SECURITY and no CREATE POLICY exist for it anywhere yet. This
 * migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state. The shared registry
 * (RowLevelSecurityCoverageMappingService) is updated once by the
 * coordinator in a later, separate wave-integration commit — not by
 * this migration.
 *
 * (a) Policy anchor: expenses carries its OWN direct, NOT NULL firm_id
 * column (see database/migrations/2026_07_16_900003_create_expenses_table.php)
 * — the policy predicate below reads that column directly. It does not
 * need to look through matter_id or expense_category_id to find a
 * firm. expenses is a genuine status-machine row (Draft -> Submitted
 * -> Approved/Rejected -> Voided); all four SQL commands are governed
 * identically by this policy, since UPDATE is a legitimate, routine
 * operation here (ExpenseService::submit()/editWhileDraft()/void() and
 * ExpenseApprovalService::recordDecision() all UPDATE this table) —
 * there is no basis for any read/write asymmetry.
 *
 * (b) Known, deliberately-deferred gaps (not fixed by this migration):
 *   - Single-hop cross-firm-mismatch: no composite foreign key or
 *     trigger ties expenses.firm_id to the ACTUAL firm_id of the
 *     matter_id/expense_category_id rows it references. PostgreSQL RLS
 *     on expenses alone cannot see into matters/expense_categories to
 *     cross-check this — that would require a structurally different
 *     EXISTS-against-parent policy, not the standard
 *     `firm_id = current_setting(...)` template used here. Today, the
 *     ONLY thing preventing this mismatch is ExpenseService::create()'s
 *     own inline `$matter->firm_id !== $firm->id` check and
 *     TenantSafeAccountingPolicyService::assertExpenseCategoryBelongsToFirm().
 *     This migration does not close that gap — it is stated here, not
 *     hidden.
 *   - Actor-attribution: created_by_firm_user_id is never asserted
 *     same-firm-as-resource by any writer service. This is orthogonal
 *     to the firm_id RLS predicate — RLS governs which firm's rows are
 *     visible/writable, not whether the actor recorded on an
 *     already-firm-scoped row is itself a member of that firm. Not
 *     something FORCE RLS is expected to close; not proposed to be
 *     closed here.
 *   - FK ON DELETE CASCADE/SET NULL/RESTRICT bypasses RLS: firm_id and
 *     created_by_firm_user_id are ->cascadeOnDelete(), matter_id is
 *     ->nullOnDelete(), expense_category_id is ->restrictOnDelete() —
 *     cascade/null actions always apply regardless of which tenant's
 *     context is currently active, expected and identical to every
 *     other cascade-on-firms table already forced in this repository.
 *
 * (c) Parent/dependent table scope: expense_categories' own FORCE
 * state is handled by the paired migration (2026_08_27_950019) landing
 * earlier in this same batch. expense_receipts, expense_approvals, and
 * accounting_export_lines (each holding a FK into this table) are
 * handled by their own paired migrations (2026_08_27_950021,
 * 2026_08_27_950022, 2026_08_27_950024 respectively) landing later in
 * this same batch. This migration makes expenses ITSELF safe under
 * FORCE; it does not itself alter any of those tables.
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
    private const TABLE = 'expenses';

    private const POLICY = 'expenses_tenant_isolation';

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
