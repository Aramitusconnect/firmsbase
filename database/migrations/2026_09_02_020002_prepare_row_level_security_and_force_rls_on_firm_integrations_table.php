<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * firm_integrations — standard canonical direct-tenant FORCE ROW LEVEL
 * SECURITY activation (Checkpoint 3), mirroring the exact SQL shape of
 * this codebase's Wave 11 webhook_subscriptions activation (see
 * database/migrations/2026_08_31_990001_prepare_row_level_security_and_force_rls_on_webhook_subscriptions_table.php),
 * the proven precedent for every one of this repository's 113 forced
 * tables. Per checkpoint-03-security-review.md and
 * domain-model-and-rls-classification.md §2, this table gets exactly
 * ONE policy — no additional narrow/carve-out policy is justified
 * here; the webhook-resolution bootstrap read is served by
 * integration_credentials' own carve-out (a future, separately-gated
 * Checkpoint 4/7 migration), deliberately minimizing new-policy
 * surface on this table.
 *
 * Command shape: combined, symmetric, FOR ALL — firm_integrations is
 * fully mutable via the eventual connection-management service layer,
 * matching the canonical template used throughout this rollout.
 *
 * Known, deliberately-deferred gap (not closed by this migration,
 * documented per this rollout's established precedent): PostgreSQL's
 * documented row-security semantics exempt foreign-key ON DELETE
 * CASCADE actions from row-security policy evaluation entirely —
 * deleting a firms row will always cascade-delete dependent
 * firm_integrations rows regardless of which tenant's context is
 * currently active. Expected, identical behavior to every other
 * cascade-on-firms table already forced in this repository.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'firm_integrations';

    private const POLICY = 'firm_integrations_tenant_isolation';

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
     * level security being enabled at all — restoring the table to its
     * true pre-this-migration state.
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
