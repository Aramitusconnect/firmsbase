<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * accounting_export_batches — the SIXTH checkpoint of this batch's
 * 7-table combined Wave 4 accounting/expense-domain activation (see
 * 2026_08_27_950018's docblock for the full combined-batch rationale).
 * Structurally the least entangled of the 7 (no in-scope FK dependency
 * of its own), grouped here for batching convenience rather than a
 * hard dependency. Like its sibling tables in this batch,
 * accounting_export_batches has NO pre-existing policy to flip FORCE
 * on for — no ENABLE ROW LEVEL SECURITY and no CREATE POLICY exist for
 * it anywhere yet. This migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state. The shared registry
 * (RowLevelSecurityCoverageMappingService) is updated once by the
 * coordinator in a later, separate wave-integration commit — not by
 * this migration.
 *
 * (a) Policy anchor: accounting_export_batches carries its OWN direct,
 * NOT NULL firm_id column (see database/migrations/2026_07_16_900007_
 * create_accounting_export_batches_table.php) — the policy predicate
 * below reads that column directly. This is a "mutable-until-terminal"
 * row (Requested -> InProgress -> Completed/CompletedWithErrors/
 * Failed/Blocked); all four SQL commands are governed identically by
 * this policy, since UPDATE is a legitimate, routine operation while
 * non-terminal (AccountingExportBatchService::markInProgress()/
 * markCompleted()/markCompletedWithErrors()/markFailed() all UPDATE
 * this table). Terminal-state immutability is already enforced at the
 * service layer via assertNotTerminal() — a business-status invariant,
 * not a tenant-isolation concern, so it is deliberately NOT folded into
 * this RLS policy.
 *
 * (b) Known, deliberately-deferred gaps (not fixed by this migration):
 *   - Actor-attribution: requested_by_firm_user_id is never asserted
 *     same-firm-as-resource by any writer service. This is orthogonal
 *     to the firm_id RLS predicate. Not something FORCE RLS is
 *     expected to close; not proposed to be closed here.
 *   - FK ON DELETE CASCADE bypasses RLS: firm_id and
 *     requested_by_firm_user_id are both ->cascadeOnDelete() — cascade
 *     actions always apply regardless of which tenant's context is
 *     currently active, expected and identical to every other
 *     cascade-on-firms table already forced in this repository.
 *
 * (c) Dependent table scope: accounting_export_lines.accounting_export_batch_id
 * (NOT NULL, ->cascadeOnDelete()) depends on this table. Its own FORCE
 * activation is handled by its own paired migration (2026_08_27_950024)
 * landing last in this same batch. This migration makes
 * accounting_export_batches ITSELF safe under FORCE; it does not
 * itself alter accounting_export_lines.
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
    private const TABLE = 'accounting_export_batches';

    private const POLICY = 'accounting_export_batches_tenant_isolation';

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
