<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * integration_usage_records — standard canonical direct-tenant FORCE
 * ROW LEVEL SECURITY activation (Checkpoint 9,
 * frozen-design-post-security-review.md §2), byte-for-byte mirroring
 * the base tenant-isolation policy shape of every prior checkpoint's
 * own combined prepare+force migration (e.g.
 * `integration_inbound_webhook_events`/`integration_connection_health`)
 * — this is the proven precedent for every one of this repository's
 * forced tables.
 *
 * Combined prepare+force in ONE migration, matching every Checkpoint
 * 3-8 entry. No carve-out policy of any kind — in particular, per this
 * mission's standing constraint, no policy here ever references
 * `credential_type` or any row-label condition on
 * `integration_credentials`.
 *
 * Command shape: combined, symmetric, FOR ALL — this table is
 * append-only at the APPLICATION layer (model-level `booted()` guard
 * throws on `updating`/`deleting`), not because the RLS policy itself
 * restricts commands; the standard symmetric policy is still correct
 * here since `App\Integrations\Services\IntegrationUsageRecorderService`
 * is the sole writer and only ever performs `INSERT`.
 *
 * Known, deliberately-deferred gap (identical to every prior forced
 * table in this rollout): PostgreSQL's documented row-security
 * semantics exempt foreign-key ON DELETE CASCADE/SET NULL actions from
 * row-security policy evaluation entirely.
 */
return new class extends Migration
{
    private const TABLE = 'integration_usage_records';

    private const POLICY = 'integration_usage_records_tenant_isolation';

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
