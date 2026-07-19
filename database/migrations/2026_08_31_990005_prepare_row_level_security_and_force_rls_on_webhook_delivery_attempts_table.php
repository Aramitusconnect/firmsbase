<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * webhook_delivery_attempts — fifth and last of the five-table Wave 11
 * (webhooks domain, FINAL wave of the 60-table rollout) FORCE ROW
 * LEVEL SECURITY batch. See 2026_08_31_990001's docblock for the full
 * batch rationale. Must be forced after webhook_deliveries (and
 * functionally after webhook_secrets, since the nullable FK below,
 * though not ownership-bearing, should exist and be consistent by
 * force time).
 *
 * Table selection rationale: webhook_delivery_attempts has hybrid
 * ownership — a direct, NOT NULL firm_id column plus a one-hop parent
 * (webhook_delivery_id -> webhook_deliveries.firm_id), plus a
 * nullable, nullOnDelete FK to webhook_secrets (webhook_secret_id) that
 * plays no ownership role (see database/migrations/
 * 2026_07_21_900005_create_webhook_delivery_attempts_table.php). The
 * WebhookDeliveryAttempt model does NOT use BelongsToTenant.
 *
 * Command shape: combined, symmetric, FOR ALL. This is the strictest
 * immutability in the domain — the model's booted() guard throws on
 * both update and delete, no exceptions carved out, $timestamps =
 * false. Kept combined rather than split for the same reasoning as
 * webhook_events and webhook_secrets: the model guard already
 * independently enforces immutability.
 *
 * Required constraints beyond the policy: same accepted single-hop-FK
 * gap as webhook_secrets/webhook_deliveries — compensated by
 * WebhookDeliveryAttemptService::recordAttempt() deriving firm_id
 * directly from $delivery->firm_id (the one production writer).
 *
 * REQUIRED co-landed companion fix — TenantSafeWebhookPolicyService now
 * has assertWebhookDeliveryAttemptBelongsToFirm(), mirroring its
 * existing four sibling methods exactly (see app/Services/
 * TenantSafeWebhookPolicyService.php). This table was previously the
 * sole hybrid-ownership table in the domain missing this defense-in-
 * depth check despite carrying the strictest immutability guarantees
 * in the domain. This companion fix does not gate FORCE RLS activation
 * itself, but lands in this same batch per the approved design.
 *
 * REQUIRED co-landed fix — this migration must not land before or
 * without WebhookDispatchJob::handle()'s context-wiring fix (see app/
 * Jobs/WebhookDispatchJob.php): this table is the write target of
 * recordAttempt(), which is called from inside handle() on every
 * branch (success, transport failure, or internal error).
 *
 * Known, deliberately-deferred gaps (not closed by this migration,
 * documented per this rollout's established precedent):
 *   1. The single-hop-FK gap described above.
 *   2. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms row will always
 *      cascade-delete dependent webhook_delivery_attempts rows
 *      regardless of which tenant's context is currently active.
 *      Expected, identical behavior to every other cascade-on-firms
 *      table already forced in this repository.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'webhook_delivery_attempts';

    private const POLICY = 'webhook_delivery_attempts_tenant_isolation';

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
