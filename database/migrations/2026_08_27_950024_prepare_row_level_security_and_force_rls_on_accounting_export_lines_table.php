<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * accounting_export_lines — the SEVENTH and LAST checkpoint of this
 * batch's 7-table combined Wave 4 accounting/expense-domain activation
 * (see 2026_08_27_950018's docblock for the full combined-batch
 * rationale). The most entangled of the 7 — three siblings
 * (accounting_export_batches, chart_of_accounts, expenses) converge
 * here, and its sole writer (AccountingExportLineBuilderService::
 * buildForBatch()) is the single call path that touches 3-of-7 tables
 * in this batch at once — so it must land after
 * accounting_export_batches (2026_08_27_950023), chart_of_accounts
 * (2026_08_27_950018), and expenses (2026_08_27_950020), all of which
 * land earlier in this same batch. Like its sibling tables in this
 * batch, accounting_export_lines has NO pre-existing policy to flip
 * FORCE on for — no ENABLE ROW LEVEL SECURITY and no CREATE POLICY
 * exist for it anywhere yet. This migration does all three steps
 * required by docs/governance/future-table-requirements.md #4/#5 in
 * one batch: ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW
 * LEVEL SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state. The shared registry
 * (RowLevelSecurityCoverageMappingService) is updated once by the
 * coordinator in a later, separate wave-integration commit — not by
 * this migration.
 *
 * (a) Policy anchor — hybrid ownership design, validated not assumed:
 * accounting_export_lines carries its OWN direct, NOT NULL firm_id
 * column (see database/migrations/2026_07_16_900008_
 * create_accounting_export_lines_table.php), present as
 * defense-in-depth — the model (App\Models\AccountingExportLine)
 * deliberately omits BelongsToTenant, since the row's documented true
 * ownership root is accounting_export_batch_id, not firm_id directly.
 * RLS policies are a pure PostgreSQL-session-level mechanism operating
 * on the physical table and its actual columns, entirely independent
 * of which Eloquent traits the corresponding model class does or does
 * not use — BelongsToTenant only controls an application-level
 * Eloquent global scope, it has zero bearing on whether PostgreSQL's
 * row-security engine can evaluate a real, physical, NOT NULL firm_id
 * column, which this table has (explicitly assigned by
 * AccountingExportLineBuilderService::buildLine() at create time —
 * 'firm_id' => $batch->firm_id — not derived implicitly). The standard
 * predicate below is therefore syntactically and semantically valid
 * and correct for what it claims to protect (this row's own firm_id
 * column), while remaining honest that it does not independently
 * verify firm_id matches accounting_export_batch_id's real owning
 * firm (or chart_of_accounts_id's, invoice_id's, payment_id's, or
 * expense_id's) — see (b) below. No EXISTS-against-parent policy is
 * used or needed here; that would be a structurally different,
 * unaddressed architectural question, not applicable since this table
 * does have its own firm_id column.
 *
 * (b) Known, deliberately-deferred gaps (not fixed by this migration):
 *   - Service-enforced-only XOR: exactly one of invoice_id/payment_id/
 *     expense_id should ever be populated on a given row (source_record_type
 *     names which). This is enforced only by
 *     AccountingExportLineBuilderService::buildLine() — no database
 *     CHECK constraint exists or is proposed to close this; a raw
 *     insert bypassing that service could set more than one, or none,
 *     of the three.
 *   - Transitive-ownership-not-verified: no composite foreign key or
 *     trigger ties this row's own firm_id to the ACTUAL firm_id of
 *     accounting_export_batch_id, chart_of_accounts_id, or whichever of
 *     invoice_id/payment_id/expense_id is populated. PostgreSQL RLS on
 *     accounting_export_lines alone cannot see into any of those four
 *     parent tables to cross-check this. Today, the ONLY thing
 *     preventing such a mismatch is
 *     AccountingExportLineBuilderService::buildLine()'s own
 *     construction (which derives firm_id directly from $batch->firm_id,
 *     the very batch accounting_export_batch_id points at). This
 *     migration does not close that gap — it is stated here, not
 *     hidden.
 *   - FK ON DELETE CASCADE/SET NULL/RESTRICT bypasses RLS:
 *     accounting_export_batch_id and firm_id are ->cascadeOnDelete(),
 *     chart_of_accounts_id is ->nullOnDelete(), invoice_id/payment_id/
 *     expense_id are ->restrictOnDelete() — cascade/null/restrict
 *     actions always apply/are evaluated regardless of which tenant's
 *     context is currently active, expected and identical to every
 *     other cascade-on-firms table already forced in this repository.
 *
 * (c) Allowed/denied commands: standard, all four SQL commands governed
 * identically. Separately, and unaffected by this policy (an existing,
 * out-of-RLS-scope mechanism, noted for completeness):
 * AccountingExportLine::booted() already throws LogicException if a row
 * whose status was already non-Pending is transitioned again ("cannot
 * be re-exported or re-failed") — a genuine, already-implemented,
 * quasi-append-only guard, independent of and unaffected by this RLS
 * policy, mirroring the ai_approval_events non-interaction precedent.
 *
 * (d) Parent table scope: accounting_export_batches, chart_of_accounts,
 * and expenses' own FORCE states are handled by their own paired
 * migrations (2026_08_27_950023, 2026_08_27_950018, 2026_08_27_950020
 * respectively), all landing earlier in this same batch. This
 * migration makes accounting_export_lines ITSELF safe under FORCE; it
 * does not itself alter any of those tables.
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
    private const TABLE = 'accounting_export_lines';

    private const POLICY = 'accounting_export_lines_tenant_isolation';

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
