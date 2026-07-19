<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * matter_trust_balances — fourth of Wave 10's ten-table batch (see
 * 2026_08_30_980001's docblock for the full batch list, ordering
 * rationale, co-landed service changes, and accepted-gap catalogue —
 * not repeated here).
 *
 * matter_trust_balances has NO pre-existing policy — this migration
 * does all three steps (ENABLE, CREATE POLICY, FORCE) in one batch,
 * per docs/governance/future-table-requirements.md #4/#5.
 *
 * Table selection rationale: matter_trust_balances carries a direct,
 * NOT NULL firm_id column (defense-in-depth, mirroring signature_
 * events' precedent), cascadeOnDelete() (see database/migrations/
 * 2026_07_18_900004_create_matter_trust_balances_table.php). The
 * MatterTrustBalance model does NOT use BelongsToTenant — a deliberate
 * design choice (mirrors the SignatureEvent/DocumentHash precedent),
 * informational only, does not change the policy shape below: the
 * firm_id column and RLS policy are identical regardless of whether
 * the model applies an additional application-layer global scope.
 * Enforcing this row can never go negative is the core mechanism
 * behind "no cross-matter use of trust funds" (project rule),
 * unaffected by this migration.
 *
 * Like trust_balances, TrustBalanceService's methods take no $firm
 * parameter and receive no new wrap of their own — every call site is
 * inside a method this batch's other service changes already wrap in
 * tenant context.
 *
 * Command shape: combined, symmetric, FOR ALL — matching every other
 * table in this batch.
 *
 * Known, deliberately-deferred gap specific to this table: no
 * composite foreign key or trigger ties trust_ledgers.firm_id or
 * matters.firm_id to this row's own firm_id — compensated by this
 * table's unique(trust_ledger_id, matter_id) constraint plus the
 * existing TrustCrossMatterProtectionService app-layer checks
 * (assertMatterEligibleForLedger()) every caller performs before
 * reaching this table. Documented as an accepted, residual gap, same
 * posture as every other single-hop-FK gap accepted in this rollout.
 *
 * The table name is validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'matter_trust_balances';

    private const POLICY = 'matter_trust_balances_tenant_isolation';

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
