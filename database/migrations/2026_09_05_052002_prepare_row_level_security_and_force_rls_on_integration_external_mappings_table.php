<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * integration_external_mappings — standard canonical direct-tenant
 * FORCE ROW LEVEL SECURITY activation (Checkpoint 6), byte-for-byte
 * mirroring every prior activation migration. Combined prepare+force
 * in one migration — no additional narrow carve-out policy justified
 * or needed (RLS is an independent, second guarantee for the
 * cross-firm case; it is NOT what prevents the same-firm,
 * two-connection collision — see the create migration's docblock and
 * this table's own uniqueness indexes for that guarantee).
 *
 * Command shape: combined, symmetric, FOR ALL —
 * integration_external_mappings is fully mutable via the sole-writer
 * IntegrationExternalMappingService.
 */
return new class extends Migration
{
    private const TABLE = 'integration_external_mappings';

    private const POLICY = 'integration_external_mappings_tenant_isolation';

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
