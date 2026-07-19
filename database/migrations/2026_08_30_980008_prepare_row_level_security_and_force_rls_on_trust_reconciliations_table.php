<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * trust_reconciliations — eighth of Wave 10's ten-table batch (see
 * 2026_08_30_980001's docblock for the full batch list, ordering
 * rationale, co-landed service changes, and accepted-gap catalogue —
 * not repeated here). This is the single most consequence-bearing
 * dependency in this wave: TrustReconciliationService::run() previously
 * established no tenant context at all, so $account->ledgers would
 * silently return an EMPTY collection once trust_accounts/trust_ledgers
 * are forced, leaving $systemBalanceCents at 0 for the entire loop and
 * misreporting a genuine discrepancy as Balanced — a fail-open bug,
 * not merely a fail-closed inconvenience. This migration lands only
 * together with that fix: the entire method body is now wrapped in one
 * runWithFirmContext() call, a new TrustEligibilityService::
 * assertEligible($firm) pre-flight check was added (matching every
 * sibling service), and a defensive check now refuses to record a
 * reconciliation result if trust_ledgers rows for the account exist but
 * none were visible under the active context.
 *
 * trust_reconciliations has NO pre-existing policy — this migration
 * does all three steps (ENABLE, CREATE POLICY, FORCE) in one batch,
 * per docs/governance/future-table-requirements.md #4/#5.
 *
 * Table selection rationale: trust_reconciliations carries a direct,
 * NOT NULL firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_18_900010_create_trust_reconciliations_table.php). The
 * TrustReconciliation model uses BelongsToTenant + HasPublicUuid — a
 * genuine tenant-owned row, a periodic firm-initiated snapshot
 * comparing summed trust_balances against a manually-asserted bank
 * statement balance. TrustReconciliationService never auto-corrects a
 * discrepancy — functionally write-once-then-terminal.
 *
 * Command shape: combined, symmetric, FOR ALL — matching every other
 * table in this batch.
 *
 * Known, deliberately-deferred gap specific to this table:
 * trust_account_id (required) has no composite foreign key or trigger
 * tying trust_accounts.firm_id to this row's own firm_id — compensated
 * by the existing TenantSafeTrustPolicyService::
 * assertTrustAccountBelongsToFirm() app-layer check
 * TrustReconciliationService::run() performs before the wrap even
 * opens. Documented per the same posture as every other single-hop-FK
 * gap accepted in this rollout.
 *
 * The table name is validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'trust_reconciliations';

    private const POLICY = 'trust_reconciliations_tenant_isolation';

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
