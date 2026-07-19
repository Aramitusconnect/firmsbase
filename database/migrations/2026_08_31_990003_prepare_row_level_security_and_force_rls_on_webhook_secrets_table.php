<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * webhook_secrets — third of the five-table Wave 11 (webhooks domain,
 * FINAL wave of the 60-table rollout) FORCE ROW LEVEL SECURITY batch.
 * See 2026_08_31_990001's docblock for the full batch rationale. Must
 * be forced after (or atomically with) webhook_subscriptions.
 *
 * Table selection rationale: webhook_secrets has hybrid ownership — a
 * direct, NOT NULL firm_id column (defense-in-depth) plus a one-hop
 * parent via webhook_subscription_id -> webhook_subscriptions.firm_id
 * (see database/migrations/2026_07_21_900003_create_webhook_secrets_table.php).
 * The WebhookSecret model deliberately does NOT use BelongsToTenant —
 * scoped transitively, defended by TenantSafeWebhookPolicyService.
 * This policy is written against the table's own direct firm_id
 * column, matching established precedent (RLS is not written against
 * the transitive path).
 *
 * Command shape: combined, symmetric, FOR ALL. This table is partially
 * append-only (status/rotated_at mutable for rotation; ciphertext/
 * encryption_key_id immutable via the model's own booted() guard) —
 * kept combined rather than split, for the same reasoning as
 * webhook_events: the model guard already independently enforces
 * column-level immutability, and a split policy would only trade that
 * guard's exception for a silent no-op.
 *
 * Required constraints beyond the policy: the pre-existing partial
 * unique index (webhook_secrets_one_active_per_subscription) already
 * enforces "one active secret per subscription" at the database layer,
 * independent of RLS — no change made here. No composite FK ties
 * webhook_subscription_id's target row's firm_id to this row's firm_id
 * — an accepted, disclosed gap (same posture as every other single-
 * hop-FK gap in this rollout), compensated by
 * WebhookSecretService::generate()/rotate() calling
 * assertWebhookSubscriptionBelongsToFirm()/
 * assertWebhookSecretBelongsToFirm() before every write.
 *
 * Disclosed, deliberate no-wrap gap: WebhookSecretService::generate()
 * and rotate() rely entirely on ambient caller-supplied tenant context
 * and have no runWithFirmContext() wrap of their own (see that
 * service's own docblock for detail). No production caller exists
 * today, so this is currently inert; this is the final wave with no
 * further cleanup pass planned, so it is disclosed explicitly here
 * rather than silently omitted — any future caller must supply
 * context explicitly or add its own wrap.
 *
 * Known, deliberately-deferred gaps (not closed by this migration,
 * documented per this rollout's established precedent):
 *   1. The single-hop-FK gap described above.
 *   2. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms row will always
 *      cascade-delete dependent webhook_secrets rows regardless of
 *      which tenant's context is currently active. Expected, identical
 *      behavior to every other cascade-on-firms table already forced
 *      in this repository.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'webhook_secrets';

    private const POLICY = 'webhook_secrets_tenant_isolation';

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
     * true pre-this-migration (MISSING_PREPARED_TABLES) state.
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
