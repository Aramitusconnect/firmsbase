<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * webhook_deliveries — fourth of the five-table Wave 11 (webhooks
 * domain, FINAL wave of the 60-table rollout) FORCE ROW LEVEL SECURITY
 * batch. See 2026_08_31_990001's docblock for the full batch rationale.
 * Must be forced after webhook_subscriptions and webhook_events.
 *
 * Table selection rationale: webhook_deliveries has hybrid ownership —
 * a direct, NOT NULL firm_id column plus two one-hop parents
 * (webhook_subscription_id, webhook_event_id), plus a nullable self-
 * referential FK (replayed_from_delivery_id) and a nullable FK to
 * firm_users (replayed_by_firm_user_id) (see database/migrations/
 * 2026_07_21_900004_create_webhook_deliveries_table.php). The
 * WebhookDelivery model does NOT use BelongsToTenant.
 *
 * Command shape: combined, symmetric, FOR ALL. This is the one table
 * in the domain genuinely mutable post-creation, but ONLY on status/
 * attempt_count/next_attempt_at/last_attempted_at, enforced by the
 * model's strict field-allowlist booted() guard (throws on any other
 * dirty field, including the replay-lineage columns). FOR ALL is
 * correct here precisely because legitimate UPDATEs exist — RLS gates
 * which firm's rows can be touched by any command; which columns is
 * the model guard's job; the two controls are complementary.
 *
 * Required constraints beyond the policy: same accepted single-hop-FK
 * gap as webhook_secrets — compensated by
 * WebhookDeliveryService::enqueue() deriving firm_id from
 * $event->firm_id and WebhookReplayService::replay() deriving it from
 * $originalDelivery after an explicit assertWebhookDeliveryBelongsToFirm()
 * call (both legitimate, already-audited, already-correctly-wrapped
 * paths). WebhookTenantIsolationTest's raw-factory negative test
 * (operating below RLS, directly against the model) is unaffected by
 * this migration.
 *
 * REQUIRED co-landed fixes — this migration must not land before or
 * without BOTH of the following:
 *   - WebhookEventRecorderService::record()'s wrap-widening fix (see
 *     2026_08_31_990002's docblock and app/Services/
 *     WebhookEventRecorderService.php) — this table is the write
 *     target of record()'s enqueue() fan-out.
 *   - WebhookDispatchJob::handle()'s context-wiring fix (see app/Jobs/
 *     WebhookDispatchJob.php) — the very first read in handle() is
 *     against this table (WebhookDelivery::query()->find(...)), and
 *     the job now requires an explicit $firmId supplied at enqueue
 *     time (never derived from a pre-context read against this same
 *     RLS-gated table, which would always return null under FORCE
 *     RLS regardless of whether the delivery exists).
 *
 * Known, deliberately-deferred gaps (not closed by this migration,
 * documented per this rollout's established precedent):
 *   1. The single-hop-FK gap described above.
 *   2. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms row will always
 *      cascade-delete dependent webhook_deliveries rows regardless of
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
    private const TABLE = 'webhook_deliveries';

    private const POLICY = 'webhook_deliveries_tenant_isolation';

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
