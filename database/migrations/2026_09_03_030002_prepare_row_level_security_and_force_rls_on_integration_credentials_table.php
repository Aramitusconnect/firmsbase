<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * integration_credentials — standard canonical direct-tenant FORCE ROW
 * LEVEL SECURITY activation (Checkpoint 4), byte-for-byte mirroring the
 * exact SQL shape of firm_integrations' own RLS migration
 * (2026_09_02_020002_prepare_row_level_security_and_force_rls_on_firm_integrations_table.php),
 * itself modeled on this codebase's Wave 11 webhook_subscriptions
 * activation — the proven precedent for every one of this repository's
 * forced tables.
 *
 * This migration ships ONLY the base tenant-isolation policy below.
 * The `integration_credentials_webhook_signing_lookup` carve-out (a
 * credential_type-scoped SELECT policy that would let a webhook-
 * delivery bootstrap read a signing secret before any firm context is
 * known) is DELIBERATELY NOT created here — per
 * checkpoint-00-final-specification.md §11(a)-(e) and Agent F's
 * security review (agent-f-security-review.md §1), that carve-out is
 * exclusively Checkpoint 7's scope, gated on its own independent
 * security review, and must never be added except via a separate,
 * later, independently-reviewed Checkpoint 7 migration. Nothing in this
 * table's schema (including the inert `webhook_routing_token` column
 * and its partial unique index added in the companion create-table
 * migration) grants any read/write authorization on its own — this is
 * the only policy of any kind installed on this table by Checkpoint 4.
 *
 * Command shape: combined, symmetric, FOR ALL — integration_credentials
 * is fully mutable via IntegrationCredentialService, matching the
 * canonical template used throughout this rollout.
 *
 * Known, deliberately-deferred gap (not closed by this migration,
 * documented per this rollout's established precedent): PostgreSQL's
 * documented row-security semantics exempt foreign-key ON DELETE
 * CASCADE actions from row-security policy evaluation entirely —
 * deleting a firms (or firm_integrations) row will always cascade-
 * delete dependent integration_credentials rows regardless of which
 * tenant's context is currently active. Expected, identical behavior to
 * every other cascade-on-firms table already forced in this repository.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'integration_credentials';

    private const POLICY = 'integration_credentials_tenant_isolation';

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
