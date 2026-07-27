<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * integration_provider_webhook_subscriptions — standard canonical
 * direct-tenant FORCE ROW LEVEL SECURITY activation (FirmsVault Live
 * Integrations, Checkpoint 2), byte-for-byte mirroring
 * integration_sync_cursors' own activation migration (this checkpoint's
 * chosen template). Combined prepare+force in one migration — no
 * additional narrow carve-out policy justified or needed.
 *
 * Classification confirmed correct by independent security review
 * (checkpoint2-security-review.md Finding 9 — "Confirmed, no finding"):
 * this table carries both firm_id and firm_integration_id and
 * represents a genuine per-firm-owned record, structurally identical in
 * ownership shape to every other per-connection Integration-domain
 * table already DirectTenant + FORCE RLS. Categorically different from
 * Checkpoint 1's integration_webhook_routing_index/
 * integration_webhook_receipts (Global, no RLS) — those tables must be
 * queryable BEFORE any tenant context can be established (attacker-
 * supplied routing tokens that may never resolve); this table's rows
 * are only ever written AFTER a connection identity and its firm
 * context are already established, inside subscribe()'s/
 * renewSubscription()'s authenticated, tenant-scoped call chain.
 *
 * Command shape: combined, symmetric, FOR ALL —
 * integration_provider_webhook_subscriptions is fully mutable via its
 * sole-writer job/command pair (RenewGraphSubscriptionJob's connect-flow
 * counterpart writes the initial row on subscribe()).
 */
return new class extends Migration
{
    private const TABLE = 'integration_provider_webhook_subscriptions';

    private const POLICY = 'integration_provider_webhook_subscriptions_tenant_isolation';

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
