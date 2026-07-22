<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * integration_sync_runs — standard canonical direct-tenant FORCE ROW
 * LEVEL SECURITY activation (Checkpoint 6), byte-for-byte mirroring the
 * base tenant-isolation policy shape of every prior Checkpoint 3/4/5
 * activation migration (firm_integrations, integration_credentials,
 * integration_oauth_states), itself modeled on this codebase's Wave 11
 * webhook_subscriptions activation. Combined prepare+force in one
 * migration (frozen-design-post-review.md §5) — no pre-existing
 * unforced state to reconcile, no additional narrow carve-out policy
 * justified or needed for this table.
 *
 * Command shape: combined, symmetric, FOR ALL — integration_sync_runs
 * is fully mutable via the sole-writer SyncRunService, matching the
 * canonical template used throughout this rollout.
 *
 * Known, deliberately-deferred gap (identical to every prior forced
 * table in this rollout): PostgreSQL's documented row-security
 * semantics exempt foreign-key ON DELETE CASCADE actions from row-
 * security policy evaluation entirely.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'integration_sync_runs';

    private const POLICY = 'integration_sync_runs_tenant_isolation';

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

    /**
     * Full rollback: this migration introduced the policy itself (there
     * was no pre-existing policy to merely un-FORCE), so down() must
     * remove all three effects up() added: FORCE, the policy, and row-
     * level security being enabled at all.
     */
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
