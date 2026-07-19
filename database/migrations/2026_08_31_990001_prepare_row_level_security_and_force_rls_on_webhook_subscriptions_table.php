<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * webhook_subscriptions — first of a five-table, one-batch FORCE ROW
 * LEVEL SECURITY activation covering the webhooks domain (Wave 11, the
 * FINAL wave of the 60-table rollout): webhook_subscriptions (this
 * migration), webhook_events (2026_08_31_990002), webhook_secrets
 * (2026_08_31_990003), webhook_deliveries (2026_08_31_990004),
 * webhook_delivery_attempts (2026_08_31_990005).
 *
 * All 5 tables are forced together in this one release: no composite
 * FK or trigger ties webhook_secrets.webhook_subscription_id,
 * webhook_deliveries.webhook_subscription_id/webhook_event_id, or
 * webhook_delivery_attempts.webhook_delivery_id back to a verified
 * matching firm_id, so forcing only a subset would create a window
 * where a parent table is RLS-protected but its child is not, or vice
 * versa. This is also the final wave — closing the backlog in one
 * release lets RowLevelSecurityCoverageMappingService::
 * MISSING_PREPARED_TABLES go to empty in a single, clean diff.
 *
 * Table selection rationale: webhook_subscriptions carries a direct,
 * NOT NULL firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_21_900001_create_webhook_subscriptions_table.php). The
 * WebhookSubscription model uses BelongsToTenant — a genuine
 * tenant-owned row, the root of the webhooks domain. No dependencies
 * on any other table in this batch (independent root).
 *
 * Command shape: combined, symmetric, FOR ALL — webhook_subscriptions
 * is fully mutable via WebhookSubscriptionService, matching every
 * other table in this batch and the canonical template used
 * throughout this rollout.
 *
 * REQUIRED co-landed application-context changes for this batch (see
 * each affected file's own docblock/inline comments for detail):
 *   - WebhookEventRecorderService::record() — widened from a narrow
 *     decoy wrap (only around the payload-builder call) to one
 *     runWithFirmContext() call covering the entire method body,
 *     including the WebhookSubscription::query()->where('firm_id', ...)
 *     read this migration's table participates in via record()'s
 *     fan-out matching step.
 *   - WebhookDispatchJob::handle() — now runs its entire body inside
 *     an explicit runInFirmContext() wrap driven by a new, required
 *     $firmId constructor argument (see app/Jobs/WebhookDispatchJob.php).
 *
 * Known, deliberately-deferred gaps (not closed by this migration,
 * documented per this rollout's established precedent):
 *   1. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms row will always
 *      cascade-delete dependent webhook_subscriptions rows regardless
 *      of which tenant's context is currently active. Expected,
 *      identical behavior to every other cascade-on-firms table
 *      already forced in this repository.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'webhook_subscriptions';

    private const POLICY = 'webhook_subscriptions_tenant_isolation';

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
