<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * trust_ledgers — second of Wave 10's ten-table batch (see
 * 2026_08_30_980001's docblock for the full batch list, ordering
 * rationale, co-landed service changes, and accepted-gap catalogue —
 * not repeated here).
 *
 * trust_ledgers has NO pre-existing policy — this migration does all
 * three steps (ENABLE, CREATE POLICY, FORCE) in one batch, per
 * docs/governance/future-table-requirements.md #4/#5.
 *
 * Table selection rationale: trust_ledgers carries a direct, NOT NULL
 * firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_18_900002_create_trust_ledgers_table.php). The TrustLedger
 * model uses BelongsToTenant + HasPublicUuid — a genuine tenant-owned
 * row, one client's IOLTA sub-ledger within a firm's pooled
 * trust_accounts row.
 *
 * Command shape: combined, symmetric, FOR ALL — trust_ledgers is fully
 * mutable via TrustLedgerService::freeze()/close() (Active -> Frozen/
 * Closed), matching every other table in this batch.
 *
 * Known, deliberately-deferred gap specific to this table (see
 * 2026_08_30_980001 for the shared catalogue): trust_account_id and
 * client_id are each a required, non-nullable FK, but no composite
 * foreign key or trigger ties trust_accounts.firm_id or clients.firm_id
 * to this row's own firm_id — only the caller-supplied TrustAccount/
 * Client objects TrustLedgerService::open() receives are trusted (it
 * does assert $client->firm_id === $firm->id explicitly, but the
 * trust_account/firm match relies on TenantSafeTrustPolicyService::
 * assertTrustAccountBelongsToFirm(), an app-layer check, not a
 * database constraint). Compensated by that existing app-layer check;
 * documented as an accepted, residual gap, same posture as every other
 * single-hop-FK gap accepted in this rollout.
 *
 * The table name is validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'trust_ledgers';

    private const POLICY = 'trust_ledgers_tenant_isolation';

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
