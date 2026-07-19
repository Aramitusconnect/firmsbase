<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * webhook_events — second of the five-table Wave 11 (webhooks domain,
 * FINAL wave of the 60-table rollout) FORCE ROW LEVEL SECURITY batch.
 * See 2026_08_31_990001's docblock for the full batch rationale.
 *
 * Table selection rationale: webhook_events carries a direct, NOT NULL
 * firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_21_900002_create_webhook_events_table.php). The WebhookEvent
 * model uses BelongsToTenant — a genuine tenant-owned row. Not a child
 * of webhook_subscriptions (independent root); subject_type/subject_id
 * are a plain polymorphic pair with no ownership role.
 *
 * Command shape: combined, symmetric, FOR ALL — kept combined rather
 * than split into FOR SELECT/FOR INSERT despite this table being
 * append-only, matching the dominant precedent for non-nullable-
 * firm_id append-only tables elsewhere in this rollout (trust_ledger_
 * entries, generated_document_events, ai_approval_events,
 * email_sync_events, form_review_events). The model's own booted()
 * guard (throws on update/delete) is the independent, already-present
 * append-only mechanism; a split policy would trade that exception for
 * a silent 0-row no-op as the failure mode on a stray mutation —
 * strictly worse, not better.
 *
 * REQUIRED co-landed fix — this migration must not land before or
 * without WebhookEventRecorderService::record()'s wrap-widening fix
 * (see app/Services/WebhookEventRecorderService.php): previously only
 * the payload-builder call was wrapped in runWithFirmContext(), while
 * WebhookEvent::create() itself ran with no active context. Under
 * FORCE RLS that decoy wrap would have caused every insert to fail the
 * WITH CHECK clause and be silently swallowed by record()'s outer
 * try/catch. The wrap now covers the entire method body as one atomic
 * unit, closing that gap before this table is forced.
 *
 * Known, deliberately-deferred gaps (not closed by this migration,
 * documented per this rollout's established precedent):
 *   1. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms row will always
 *      cascade-delete dependent webhook_events rows regardless of
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
    private const TABLE = 'webhook_events';

    private const POLICY = 'webhook_events_tenant_isolation';

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
