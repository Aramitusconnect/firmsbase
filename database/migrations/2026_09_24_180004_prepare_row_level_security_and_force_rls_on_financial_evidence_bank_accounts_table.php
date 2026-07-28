<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * financial_evidence_bank_accounts — standard canonical direct-tenant
 * FORCE ROW LEVEL SECURITY activation (FirmsVault Live Integrations,
 * Checkpoint 4), byte-for-byte mirroring
 * `integration_provider_webhook_subscriptions`'s own activation
 * migration (this checkpoint's chosen template). Combined prepare+force
 * in one migration — no additional narrow carve-out policy justified or
 * needed. This table is only ever written from inside
 * `App\Integrations\Support\FinancialEvidenceMaterializerService`,
 * itself only ever reached from `App\Jobs\PullSyncJob`'s already
 * tenant-context-established batch loop — never before real firm
 * context is active, so a symmetric FOR ALL policy is correct here
 * (unlike `integration_plaid_item_routes`, which must remain queryable
 * pre-tenant-context and therefore carries no RLS at all).
 */
return new class extends Migration
{
    private const TABLE = 'financial_evidence_bank_accounts';

    private const POLICY = 'financial_evidence_bank_accounts_tenant_isolation';

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
