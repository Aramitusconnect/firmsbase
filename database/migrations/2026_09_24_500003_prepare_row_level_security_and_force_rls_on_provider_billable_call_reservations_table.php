<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * provider_billable_call_reservations — standard canonical direct-tenant
 * FORCE ROW LEVEL SECURITY activation (FirmsVault Live Integrations,
 * Checkpoint 4), byte-for-byte mirroring
 * integration_provider_webhook_subscriptions' own activation migration
 * (database/migrations/2026_09_22_160002_prepare_row_level_security_and_force_rls_on_integration_provider_webhook_subscriptions_table.php)
 * — combined prepare+force in one migration, no additional narrow
 * carve-out policy justified or needed. This table carries both
 * firm_id and firm_integration_id and represents a genuine
 * per-firm-owned record (checkpoint4-combined-design.md §10).
 *
 * Command shape: combined, symmetric, FOR ALL —
 * App\Integrations\Billing\ProviderUsageReservationService is the sole
 * writer/transitioner.
 */
return new class extends Migration
{
    private const TABLE = 'provider_billable_call_reservations';

    private const POLICY = 'provider_billable_call_reservations_tenant_isolation';

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
