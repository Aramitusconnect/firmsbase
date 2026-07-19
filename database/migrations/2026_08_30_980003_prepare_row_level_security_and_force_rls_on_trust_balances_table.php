<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * trust_balances — third of Wave 10's ten-table batch (see
 * 2026_08_30_980001's docblock for the full batch list, ordering
 * rationale, co-landed service changes, and accepted-gap catalogue —
 * not repeated here).
 *
 * trust_balances has NO pre-existing policy — this migration does all
 * three steps (ENABLE, CREATE POLICY, FORCE) in one batch, per
 * docs/governance/future-table-requirements.md #4/#5.
 *
 * Table selection rationale: trust_balances carries a direct, NOT NULL
 * firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_18_900003_create_trust_balances_table.php). The TrustBalance
 * model uses BelongsToTenant + HasPublicUuid — a genuine tenant-owned
 * row, a cached balance recomputed exclusively by
 * TrustBalanceService::recomputeForLedger() as SUM(trust_ledger_
 * entries.amount_cents) for its trust_ledger_id (unique — one row per
 * ledger). No other service ever writes this table.
 *
 * TrustBalanceService's methods take no $firm parameter and receive no
 * new wrap of their own in this batch — every one of their 6 call
 * sites is inside a method this batch's service changes already wrap
 * in tenant context (see 2026_08_30_980001), so this table's writes
 * are protected entirely by caller-side wraps landing in the same
 * release.
 *
 * Command shape: combined, symmetric, FOR ALL — matching every other
 * table in this batch and the canonical template used throughout this
 * rollout.
 *
 * Known, deliberately-deferred gap specific to this table: no
 * composite foreign key or trigger ties trust_ledgers.firm_id to this
 * row's own firm_id — compensated by trust_balances.trust_ledger_id
 * being unique and exclusively written by TrustBalanceService against
 * a $ledger object every caller has already asserted belongs to the
 * active firm via TenantSafeTrustPolicyService before reaching this
 * table. Documented as an accepted, residual gap, same posture as
 * every other single-hop-FK gap accepted in this rollout.
 *
 * The table name is validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'trust_balances';

    private const POLICY = 'trust_balances_tenant_isolation';

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
