<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * expense_receipts — the FOURTH checkpoint of this batch's 7-table
 * combined Wave 4 accounting/expense-domain activation (see
 * 2026_08_27_950018's docblock for the full combined-batch rationale).
 * Like its sibling tables in this batch, expense_receipts has NO
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
 * (a) Policy anchor: expense_receipts carries its OWN direct, NOT NULL
 * firm_id column (see database/migrations/2026_07_16_900004_
 * create_expense_receipts_table.php) — the policy predicate below
 * reads that column directly. It does not need to look through
 * expense_id to find a firm. expense_receipts is a true 1:1 child of
 * expenses (expense_id is UNIQUE, ->cascadeOnDelete()); today only
 * ExpenseReceiptService::upload() writes to it (no UPDATE method
 * exists yet), but the standard combined USING/WITH CHECK shape (not a
 * narrower policy) is used regardless — narrowing to deny UPDATE at
 * the database layer would be a false, unrequested guarantee not asked
 * for by product, mirroring chart_of_accounts' identical
 * one-write-method-today position elsewhere in this same batch.
 *
 * (b) Known, deliberately-deferred gaps (not fixed by this migration):
 *   - Single-hop cross-firm-mismatch: no composite foreign key or
 *     trigger ties expense_receipts.firm_id to the ACTUAL firm_id of
 *     the expenses row expense_id points at. PostgreSQL RLS on
 *     expense_receipts alone cannot see into expenses to cross-check
 *     this — that would require a structurally different
 *     EXISTS-against-parent policy, not the standard
 *     `firm_id = current_setting(...)` template used here. Today, the
 *     ONLY thing preventing this mismatch is
 *     ExpenseReceiptService::upload()'s own
 *     TenantSafeAccountingPolicyService::assertExpenseBelongsToFirm()
 *     call. This migration does not close that gap — it is stated
 *     here, not hidden.
 *   - Actor-attribution: uploaded_by_firm_user_id is never asserted
 *     same-firm-as-resource by any writer service. This is orthogonal
 *     to the firm_id RLS predicate. Not something FORCE RLS is
 *     expected to close; not proposed to be closed here.
 *   - FK ON DELETE CASCADE/SET NULL bypasses RLS: firm_id and
 *     expense_id are ->cascadeOnDelete(), encryption_key_id and
 *     uploaded_by_firm_user_id are ->nullOnDelete() — cascade/null
 *     actions always apply regardless of which tenant's context is
 *     currently active, expected and identical to every other
 *     cascade-on-firms table already forced in this repository.
 *
 * (c) Parent table scope: expenses' own FORCE state is handled by the
 * paired migration (2026_08_27_950020) landing earlier in this same
 * batch. This migration makes expense_receipts ITSELF safe under
 * FORCE; it does not itself alter expenses.
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
    private const TABLE = 'expense_receipts';

    private const POLICY = 'expense_receipts_tenant_isolation';

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
