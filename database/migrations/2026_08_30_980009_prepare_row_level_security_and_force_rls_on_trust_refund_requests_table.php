<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * trust_refund_requests — ninth of Wave 10's ten-table batch (see
 * 2026_08_30_980001's docblock for the full batch list, ordering
 * rationale, co-landed service changes, and accepted-gap catalogue —
 * not repeated here). complete() previously had a decoy-wrap bug
 * (isolated narrow wraps around unrelated reads while the actual
 * trust-table writes in between ran unwrapped) — now collapsed into
 * one outer whole-method wrap, with the pre-existing narrow wrap
 * surviving unchanged as a nested child; requestRefund()/
 * approveRefund()/denyRefund() each received their own straightforward
 * whole-method wrap.
 *
 * trust_refund_requests has NO pre-existing policy — this migration
 * does all three steps (ENABLE, CREATE POLICY, FORCE) in one batch,
 * per docs/governance/future-table-requirements.md #4/#5.
 *
 * Table selection rationale: trust_refund_requests carries a direct,
 * NOT NULL firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_18_900006_create_trust_refund_requests_table.php). The
 * TrustRefundRequest model uses BelongsToTenant + HasPublicUuid — a
 * genuine tenant-owned row, the request/approve/deny/complete
 * refund-to-client workflow (no Payment/invoice integration, unlike a
 * transfer).
 *
 * Command shape: combined, symmetric, FOR ALL — matching every other
 * table in this batch.
 *
 * Known, deliberately-deferred gap specific to this table:
 * trust_ledger_id (required) and matter_id (nullable) have no
 * composite foreign key or trigger tying trust_ledgers.firm_id/
 * matters.firm_id to this row's own firm_id — compensated by the
 * existing TenantSafeTrustPolicyService::assertTrustLedgerBelongsToFirm()
 * and TrustCrossMatterProtectionService::assertMatterEligibleForLedger()
 * app-layer checks every writer method performs before creating/using
 * this row. Documented per the same posture as every other
 * single-hop-FK gap accepted in this rollout.
 *
 * The table name is validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'trust_refund_requests';

    private const POLICY = 'trust_refund_requests_tenant_isolation';

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
