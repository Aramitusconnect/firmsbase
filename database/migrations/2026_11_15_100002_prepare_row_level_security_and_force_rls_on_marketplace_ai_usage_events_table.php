<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mission 3, checkpoint 6 — the marketplace_ai_usage_events RLS shape,
 * copied byte-for-byte from security_events' own Phase B6 read/write
 * policy pair (see database/migrations/2026_08_25_930034_force_rls_on_
 * security_events_table.php's own docblock for the full design
 * rationale this mirrors): a firm-scoped session sees only its own
 * firm's rows; a context-free session sees only the platform-wide
 * (firm_id IS NULL) rows; no context state ever grants visibility
 * into another firm's rows. Prepared and forced together in one
 * migration, same as every other new table added after the original
 * staged rollout closed.
 */
return new class extends Migration
{
    private const TABLE = 'marketplace_ai_usage_events';

    private const READ_POLICY = 'marketplace_ai_usage_events_tenant_isolation';

    private const WRITE_POLICY = 'marketplace_ai_usage_events_platform_write';

    public function up(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");

        DB::statement(<<<SQL
            CREATE POLICY {$this->quoteIdentifier(self::READ_POLICY)}
            ON {$table}
            FOR SELECT
            USING (
                firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
                OR (firm_id IS NULL AND NULLIF(current_setting('app.current_firm_id', true), '')::bigint IS NULL)
            )
        SQL);

        DB::statement(<<<SQL
            CREATE POLICY {$this->quoteIdentifier(self::WRITE_POLICY)}
            ON {$table}
            FOR INSERT
            WITH CHECK (
                firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
                OR (firm_id IS NULL AND NULLIF(current_setting('app.current_firm_id', true), '')::bigint IS NULL)
            )
        SQL);

        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }

    public function down(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);

        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY {$this->quoteIdentifier(self::WRITE_POLICY)} ON {$table}");
        DB::statement("DROP POLICY {$this->quoteIdentifier(self::READ_POLICY)} ON {$table}");
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
