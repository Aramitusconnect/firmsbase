<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * expense_approvals — the FIFTH checkpoint of this batch's 7-table
 * combined Wave 4 accounting/expense-domain activation (see
 * 2026_08_27_950018's docblock for the full combined-batch rationale).
 * Like its sibling tables in this batch, expense_approvals has NO
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
 * (a) Policy anchor: expense_approvals carries its OWN direct, NOT NULL
 * firm_id column (see database/migrations/2026_07_16_900005_
 * create_expense_approvals_table.php) — the policy predicate below
 * reads that column directly.
 *
 * (b) Append-only note — the SAME standard combined USING/WITH CHECK
 * policy shape as every other table in this batch is used here too,
 * with NO command-specific (FOR INSERT-only) narrowing, mirroring the
 * ai_approval_events precedent (2026_08_27_950017) exactly: this
 * migration's WITH CHECK clause governs INSERT-time firm ownership
 * only, and is NOT the append-only enforcement mechanism for this
 * table. ExpenseApprovalService only ever calls ExpenseApproval::create()
 * (no update/delete method exists in that service), and, as of this
 * same batch, ExpenseApproval::booted() now throws LogicException on
 * update/delete (mirroring AiApprovalEvent::booted() exactly) — that
 * model-level guard, not this RLS policy, is what makes append-only-
 * ness a real, enforced guarantee rather than a merely-conventional
 * one. Prior to this batch, ExpenseApproval had no such guard at all;
 * adding it is a narrow, explicitly-scoped companion model change
 * landing in this same batch (see app/Models/ExpenseApproval.php).
 *
 * (c) Known, deliberately-deferred gaps (not fixed by this migration):
 *   - Single-hop cross-firm-mismatch: no composite foreign key or
 *     trigger ties expense_approvals.firm_id to the ACTUAL firm_id of
 *     the expenses row expense_id points at. PostgreSQL RLS on
 *     expense_approvals alone cannot see into expenses to cross-check
 *     this — that would require a structurally different
 *     EXISTS-against-parent policy, not the standard
 *     `firm_id = current_setting(...)` template used here. Today, the
 *     ONLY thing preventing this mismatch is
 *     ExpenseApprovalService::recordDecision()'s own
 *     TenantSafeAccountingPolicyService::assertExpenseBelongsToFirm()
 *     call. This migration does not close that gap — it is stated
 *     here, not hidden.
 *   - Actor-attribution: decided_by_firm_user_id is never asserted
 *     same-firm-as-resource; AccountingEntitlementPolicyService::
 *     assertCanApprove() checks role only, never firm membership. Not
 *     something FORCE RLS is expected to close; not proposed to be
 *     closed here.
 *   - ExpenseApprovalService::approve()/reject() forward-looking
 *     tautological-authorization design risk: both methods accept
 *     `Firm $firm` as an independently-supplied caller parameter with
 *     no enforcement that it derives from the authenticated actor
 *     rather than the resource. Unexploitable today (no controller/
 *     Filament resource exists for this domain), but must be resolved
 *     — `$firm` must be resolved from the authenticated actor, never
 *     accepted as a caller-supplied value derived from the resource —
 *     before any controller/Filament resource is ever built here. This
 *     is a load-bearing constraint for future work, not something this
 *     RLS activation fixes.
 *   - FK ON DELETE CASCADE/SET NULL bypasses RLS: firm_id and
 *     expense_id are ->cascadeOnDelete(), decided_by_firm_user_id is
 *     ->nullOnDelete() — cascade/null actions always apply regardless
 *     of which tenant's context is currently active, expected and
 *     identical to every other cascade-on-firms table already forced
 *     in this repository.
 *
 * (d) Parent table scope: expenses' own FORCE state is handled by the
 * paired migration (2026_08_27_950020) landing earlier in this same
 * batch. This migration makes expense_approvals ITSELF safe under
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
    private const TABLE = 'expense_approvals';

    private const POLICY = 'expense_approvals_tenant_isolation';

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
