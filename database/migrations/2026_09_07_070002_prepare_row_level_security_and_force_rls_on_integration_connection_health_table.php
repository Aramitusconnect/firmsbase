<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * integration_connection_health — standard canonical direct-tenant
 * FORCE ROW LEVEL SECURITY activation (Checkpoint 8,
 * agent-8f-health-state-design.md §6;
 * agent-8h-architecture-security-review.md §1 item 6), byte-for-byte
 * mirroring the base tenant-isolation policy shape of every prior
 * checkpoint's own combined prepare+force migration — reproduced here
 * verbatim from
 * `2026_09_06_060004_prepare_row_level_security_and_force_rls_on_integration_inbound_webhook_events_table.php`,
 * table/policy name substitution only.
 *
 * Combined prepare+force in ONE migration, matching every Checkpoint
 * 3-7 entry — no additional narrow carve-out policy is justified or
 * needed for this table: by the time a row is written, real firm
 * context has already been established (every writer is a job that
 * already knows its firmId before calling HealthStateService),
 * identical reasoning to `integration_inbound_webhook_events`.
 *
 * Command shape: combined, symmetric, FOR ALL — this table is fully
 * mutable only via the sole-writer
 * App\Integrations\Services\HealthStateService.
 *
 * No RLS policy predicate anywhere in this migration references
 * `credential_type` — permanently forbidden per this mission's
 * foundational security principle established at Checkpoint 7.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'integration_connection_health';

    private const POLICY = 'integration_connection_health_tenant_isolation';

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
