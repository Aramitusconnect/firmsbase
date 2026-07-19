<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * trust_approval_events — sixth of Wave 10's ten-table batch (see
 * 2026_08_30_980001's docblock for the full batch list, ordering
 * rationale, co-landed service changes, and accepted-gap catalogue —
 * not repeated here). This table is the universal pre-flight gate for
 * the ENTIRE trust domain: TrustEligibilityService::
 * hasApprovedTrustSetup() reads it directly, and evaluate()/isEligible()/
 * assertEligible() is the literal first statement of essentially every
 * method across 7 Trust services (~25 confirmed call sites). The
 * REQUIRED fix landing in this same release — a new, separate, narrow
 * runWithFirmContext() wrap around the hasApprovedTrustSetup($firm)
 * call in TrustEligibilityService::evaluate() — is not optional: without
 * it, this table's own FORCE RLS activation would make that gate return
 * false unconditionally for every firm, breaking the entire domain on
 * deploy.
 *
 * trust_approval_events has NO pre-existing policy — this migration
 * does all three steps (ENABLE, CREATE POLICY, FORCE) in one batch,
 * per docs/governance/future-table-requirements.md #4/#5.
 *
 * Table selection rationale: trust_approval_events carries a direct,
 * NOT NULL firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_18_900007_create_trust_approval_events_table.php) — confirmed
 * NOT the Wave-8 no-firm_id risk shape. The TrustApprovalEvent model
 * does NOT use BelongsToTenant (deliberate, mirrors the SignatureEvent/
 * DocumentHash precedent) — informational only, does not change the
 * policy shape below. This is a genuinely append-only table, enforced
 * additionally by the model's own booted() guard, independent of and
 * complementary to RLS.
 *
 * Command shape: combined, symmetric, FOR ALL — matching every other
 * table in this batch.
 *
 * Known, deliberately-deferred gap specific to this table: matter_id,
 * trust_ledger_id, trust_transfer_request_id, trust_refund_request_id,
 * and high_risk_change_request_id are all nullable FKs with no
 * composite foreign key or trigger tying their target rows' own
 * firm_id to this row's firm_id — only the caller-supplied objects each
 * writer service receives are trusted. Compensated by the existing
 * TenantSafeTrustPolicyService app-layer checks (e.g.
 * assertTrustApprovalEventBelongsToFirm()) performed before every
 * write/read that matters. Documented per the same posture as every
 * other single-hop-FK gap accepted in this rollout.
 *
 * The table name is validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'trust_approval_events';

    private const POLICY = 'trust_approval_events_tenant_isolation';

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
