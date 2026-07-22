<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * integration_inbound_webhook_events — standard canonical direct-tenant
 * FORCE ROW LEVEL SECURITY activation (Checkpoint 7,
 * reviews/checkpoint-07/frozen-design-post-security-review.md §10.2),
 * byte-for-byte mirroring the base tenant-isolation policy shape of
 * every prior checkpoint's own combined prepare+force migration (e.g.
 * `integration_oauth_states`/`integration_credentials`/
 * `integration_outbox_events`) — this is the proven precedent for
 * every one of this repository's forced tables.
 *
 * Combined prepare+force in ONE migration, matching every Checkpoint
 * 3-6 entry — no additional narrow carve-out policy is justified or
 * needed for this table: by the time a row is written, real firm
 * context has already been established via the credential/routing
 * resolution described in the frozen design's §5 (WebhookConnectionResolverService
 * -> TenantContextService::runWithFirmContext() ->
 * IntegrationCredentialService::findActiveCredential()/
 * decryptForOperation() -> InboundWebhookSignatureVerifier), unlike
 * `integration_oauth_states`, which genuinely needs its own
 * self-lookup carve-out to bootstrap an OAuth callback BEFORE any firm
 * context can be known.
 *
 * Command shape: combined, symmetric, FOR ALL — this table is fully
 * mutable via the sole-writer
 * App\Integrations\Services\InboundWebhookEventService.
 *
 * `integration_webhook_routing_index` and `integration_webhook_receipts`
 * (the other two new Checkpoint 7 tables) deliberately have NO
 * companion RLS migration at all — see each of their own create
 * migrations' "WHY THIS TABLE HAS NO RLS" docblocks for the required
 * reasoning. This is the ONLY one of Checkpoint 7's three new tables
 * that receives RLS.
 *
 * Known, deliberately-deferred gap (identical to every prior forced
 * table in this rollout): PostgreSQL's documented row-security
 * semantics exempt foreign-key ON DELETE CASCADE/SET NULL actions from
 * row-security policy evaluation entirely — deleting a firms (or
 * firm_integrations) row will always cascade-delete dependent
 * integration_inbound_webhook_events rows regardless of which tenant's
 * context is currently active.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'integration_inbound_webhook_events';

    private const POLICY = 'integration_inbound_webhook_events_tenant_isolation';

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
