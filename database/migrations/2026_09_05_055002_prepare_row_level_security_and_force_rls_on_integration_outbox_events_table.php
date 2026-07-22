<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * integration_outbox_events — standard canonical direct-tenant FORCE
 * ROW LEVEL SECURITY activation (Checkpoint 6), byte-for-byte
 * mirroring every prior activation migration. Combined prepare+force
 * in one migration — no additional narrow carve-out policy justified
 * or needed; the claim query itself always supplies a firm_id
 * predicate in addition to whatever tenant context is active (see
 * IntegrationOutboxEventService::claim()).
 *
 * Command shape: combined, symmetric, FOR ALL — integration_outbox_events
 * is fully mutable via the sole-writer IntegrationOutboxEventService.
 *
 * This is the LAST migration of the Checkpoint 6 date block —
 * RowLevelSecurityCoverageMappingService::PREPARED_TABLES goes
 * 116 -> 122 once all six of this checkpoint's tables are registered.
 */
return new class extends Migration
{
    private const TABLE = 'integration_outbox_events';

    private const POLICY = 'integration_outbox_events_tenant_isolation';

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
