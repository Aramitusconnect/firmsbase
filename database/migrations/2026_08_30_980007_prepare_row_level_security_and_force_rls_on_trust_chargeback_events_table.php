<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * trust_chargeback_events — seventh of Wave 10's ten-table batch (see
 * 2026_08_30_980001's docblock for the full batch list, ordering
 * rationale, co-landed service changes, and accepted-gap catalogue —
 * not repeated here). This table's activation is gated on
 * TrustChargebackService::reverse()'s crash-risk fix landing in this
 * same release: that method previously had ZERO tenant-context wrap of
 * any kind, and accessed a lazy-loaded relation chain
 * ($chargeback->originalEntry->trustLedger) with no null-safety — now
 * fixed with a whole-method wrap plus a defensive, named-exception
 * null-check.
 *
 * trust_chargeback_events has NO pre-existing policy — this migration
 * does all three steps (ENABLE, CREATE POLICY, FORCE) in one batch,
 * per docs/governance/future-table-requirements.md #4/#5.
 *
 * Table selection rationale: trust_chargeback_events carries a direct,
 * NOT NULL firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_18_900009_create_trust_chargeback_events_table.php). The
 * TrustChargebackEvent model uses BelongsToTenant + HasPublicUuid — a
 * genuine tenant-owned row. Unlike the append-only tables in this
 * batch, this table has a real lifecycle (Reported -> Reversed ->
 * Resolved) via ordinary update() calls.
 *
 * Command shape: combined, symmetric, FOR ALL — trust_chargeback_events
 * is fully mutable via TrustChargebackService::reverse()/resolve(),
 * matching every other table in this batch.
 *
 * Known, deliberately-deferred gap specific to this table:
 * original_trust_ledger_entry_id (required) and reversal_trust_ledger_
 * entry_id (nullable, both restrictOnDelete()) have no composite
 * foreign key or trigger tying trust_ledger_entries.firm_id to this
 * row's own firm_id — compensated by the existing
 * TenantSafeTrustPolicyService::assertTrustLedgerEntryBelongsToFirm()
 * app-layer check TrustChargebackService::report() performs before
 * creating this row. Documented per the same posture as every other
 * single-hop-FK gap accepted in this rollout.
 *
 * The table name is validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'trust_chargeback_events';

    private const POLICY = 'trust_chargeback_events_tenant_isolation';

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
