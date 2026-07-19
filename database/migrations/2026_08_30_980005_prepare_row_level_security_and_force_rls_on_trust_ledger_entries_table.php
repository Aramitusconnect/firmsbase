<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * trust_ledger_entries — fifth of Wave 10's ten-table batch (see
 * 2026_08_30_980001's docblock for the full batch list, ordering
 * rationale, co-landed service changes, and accepted-gap catalogue —
 * not repeated here). This is the highest-consequence table in the
 * batch: 5 writer services (TrustDepositService::post(),
 * TrustTransferRequestService::apply(),
 * TrustRefundRequestService::complete(),
 * TrustHighRiskAdjustmentService::secondApprove(),
 * TrustLedgerEntryReversalService::reverse()) all write here, every
 * one of them now wrapped in this same release.
 *
 * trust_ledger_entries has NO pre-existing policy — this migration
 * does all three steps (ENABLE, CREATE POLICY, FORCE) in one batch,
 * per docs/governance/future-table-requirements.md #4/#5.
 *
 * Table selection rationale: trust_ledger_entries carries a direct,
 * NOT NULL firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_18_900008_create_trust_ledger_entries_table.php). The
 * TrustLedgerEntry model does NOT use BelongsToTenant (deliberate,
 * mirrors the SignatureEvent/DocumentHash precedent) — informational
 * only, does not change the policy shape below. This is a genuinely
 * append-only table: a row is created once and NEVER updated or
 * deleted, additionally enforced by the model's own booted() guard,
 * independent of and complementary to RLS.
 *
 * Command shape: combined, symmetric, FOR ALL — the append-only guard
 * and RLS are independent, complementary controls; RLS's own WITH
 * CHECK still governs every INSERT regardless of the model-layer
 * guard blocking UPDATE/DELETE.
 *
 * Known, deliberately-deferred gaps specific to this table: matter_id,
 * reverses_entry_id, trust_approval_event_id, trust_transfer_request_
 * id, trust_refund_request_id, and source_payment_id are all nullable
 * FKs with no composite foreign key or trigger tying their target
 * rows' own firm_id to this row's firm_id — only the caller-supplied
 * objects each writer service receives are trusted, compensated by the
 * existing TenantSafeTrustPolicyService/TrustCrossMatterProtectionService
 * app-layer checks performed before every write. The pre-existing,
 * already-tracked (in ComplianceGapRegistryService)
 * trust_ledger_entry_posting_actor_not_guaranteed gap is unrelated to
 * this wave's scope and is not touched here — this migration does not
 * add new columns or alter this table's schema. Documented per the
 * same posture as every other single-hop-FK gap accepted in this
 * rollout.
 *
 * The table name is validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'trust_ledger_entries';

    private const POLICY = 'trust_ledger_entries_tenant_isolation';

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
