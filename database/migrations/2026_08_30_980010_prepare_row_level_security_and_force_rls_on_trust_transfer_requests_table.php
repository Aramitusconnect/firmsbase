<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * trust_transfer_requests — tenth and last of Wave 10's ten-table batch
 * (see 2026_08_30_980001's docblock for the full batch list, ordering
 * rationale, co-landed service changes, and accepted-gap catalogue —
 * not repeated here). Deliberately forced last: apply() has the
 * highest cross-table blast radius in the domain, reaching into
 * payments/payment_classification_events outside this batch as well as
 * matters/invoices. apply() previously had a decoy-wrap bug (isolated
 * narrow wraps around matters/invoices reads, Payment::create(),
 * classify()+recordDecision(), and applyToInvoice(), while the actual
 * trust-table writes in between — the WithdrawalToInvoice
 * TrustLedgerEntry::create(), both balance recomputes, $request->
 * update(), and the trailing TrustApprovalEvent::create() — ran
 * unwrapped) — now collapsed into one outer whole-method wrap, with
 * all 4 pre-existing narrow wraps surviving unchanged as nested
 * children; requestTransfer()/approveTransfer()/denyTransfer() each
 * received their own straightforward whole-method wrap.
 *
 * trust_transfer_requests has NO pre-existing policy — this migration
 * does all three steps (ENABLE, CREATE POLICY, FORCE) in one batch,
 * per docs/governance/future-table-requirements.md #4/#5.
 *
 * Table selection rationale: trust_transfer_requests carries a direct,
 * NOT NULL firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_18_900005_create_trust_transfer_requests_table.php). The
 * TrustTransferRequest model uses BelongsToTenant + HasPublicUuid — a
 * genuine tenant-owned row, the trust-to-invoice transfer workflow root
 * (request/approve/deny/apply).
 *
 * Command shape: combined, symmetric, FOR ALL — matching every other
 * table in this batch.
 *
 * Known, deliberately-deferred gap specific to this table:
 * trust_ledger_id, matter_id, and invoice_id (all NOT NULL) have no
 * composite foreign key or trigger tying trust_ledgers.firm_id/
 * matters.firm_id/invoices.firm_id to this row's own firm_id —
 * compensated by the existing TenantSafeTrustPolicyService::
 * assertTrustLedgerBelongsToFirm(),
 * TrustCrossMatterProtectionService::assertMatterEligibleForLedger(),
 * and the explicit invoice firm_id/matter_id match check
 * requestTransfer() performs before creating this row. Documented per
 * the same posture as every other single-hop-FK gap accepted in this
 * rollout.
 *
 * The table name is validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'trust_transfer_requests';

    private const POLICY = 'trust_transfer_requests_tenant_isolation';

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
