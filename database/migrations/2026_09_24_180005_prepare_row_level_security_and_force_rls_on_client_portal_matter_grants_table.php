<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * client_portal_matter_grants — standard canonical direct-tenant FORCE
 * ROW LEVEL SECURITY activation (Checkpoint 4, "Plaid financial
 * evidence add-on" — Client Portal authentication foundation),
 * byte-for-byte mirroring the base tenant-isolation policy shape of
 * `firm_integrations`/`integration_credentials`'s own RLS migrations,
 * itself modeled on this codebase's Wave 11 webhook_subscriptions
 * activation — the proven precedent for every one of this repository's
 * forced tables. Combined prepare+force in one migration — no
 * additional narrow carve-out policy justified or needed (this table
 * has a real, direct, NOT NULL firm_id column and no bootstrap
 * problem: it is only ever read/written by an already-tenant-context-
 * established Firm-panel staff action or an already-two-hop-resolved
 * Client Portal request).
 *
 * Command shape: combined, symmetric, FOR ALL — client_portal_matter_grants
 * is fully mutable via ordinary Firm-panel staff actions (granting/
 * revoking portal visibility for a matter), matching the canonical
 * template used throughout this rollout.
 */
return new class extends Migration
{
    private const TABLE = 'client_portal_matter_grants';

    private const POLICY = 'client_portal_matter_grants_tenant_isolation';

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
