<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * integration_webhook_receipts — Checkpoint 7 (canonical name, no
 * `inbound_` prefix — per agent-7h-security-design-review.md §1.1: no
 * `webhook_receipts` table exists anywhere in this codebase, so this
 * table never needed the prefix the sibling
 * `integration_inbound_webhook_events` table needs to avoid colliding
 * with the pre-existing, unrelated, outbound-only `webhook_events`
 * table). Platform-owned, pre-tenant intake row for every inbound
 * webhook delivery whose routing token successfully resolved to a
 * connection (frozen-design-post-security-review.md §10.1).
 *
 * NO RLS AT ALL — same exemption class as `integration_providers` and
 * `integration_webhook_routing_index` (see those tables' own "WHY THIS
 * TABLE HAS NO RLS" docblocks). Explicitly REJECTED here (per
 * agent-7h-security-design-review.md §1.3): a 7D-proposed alternative
 * design that would `ENABLE`/`FORCE ROW LEVEL SECURITY` on this table
 * gated by a novel `app.platform_webhook_ingestion_active` session GUC
 * — that mechanism is a real, undisclosed deviation from the
 * already-frozen Checkpoint 0 spec text ("platform pre-tenant, no RLS
 * at all — approved exemption — not a nullable-firm_id policy") and
 * remains available only as a FUTURE, separately-reviewed, optional
 * hardening, never as part of this checkpoint's required scope.
 *
 * NEVER STORES `firm_id` OR `firm_integration_id`, EVEN
 * POST-VERIFICATION (frozen design §10.1, adopting 7D's tenant-
 * blindness property) — structurally incapable of holding a
 * tenant-identifying column: no such column exists on this table at
 * all, and none may ever be added. The legitimate path to discover
 * "which firm did this belong to" is
 * `integration_inbound_webhook_events.receipt_id` pointing BACK to a
 * specific receipt row — a second, RLS-unenforced FORWARD pointer on
 * this table (receipt -> firm) would only add a de-anonymization
 * channel with no legitimate access-path benefit, since every
 * legitimate reader of a receipt row already operates pre-tenant
 * (platform-only diagnostics), never as a specific firm's session.
 *
 * `routing_token_hash` — sha256 hex digest of the resolved routing
 * token, NOT NULL. A row is only ever inserted AFTER
 * WebhookConnectionResolverService::resolveConnectionIdentity() has
 * already succeeded (frozen design §10.1's required consequence #1) —
 * if routing resolution fails, or if signature verification
 * subsequently fails, NO receipt row is written at all (see
 * App\Integrations\Http\Controllers\InboundWebhookController and
 * App\Integrations\Services\InboundWebhookReceiptService for the exact
 * write-gating). This column being connection-scoped (via
 * `integration_webhook_routing_index.webhook_routing_token_hash`'s own
 * GLOBAL uniqueness — see that table's create migration) is what makes
 * this table's idempotency key below safe without this table ever
 * carrying a firm-resolving FK.
 *
 * `body_hash` — sha256 of the raw request body. The RAW BODY ITSELF IS
 * NEVER PERSISTED anywhere on this table or any other (frozen design
 * §13 — the Checkpoint 0 spec's encrypted-raw-body exception is
 * explicitly NOT implemented at Checkpoint 7; see this checkpoint's
 * frozen design document §13/§16 for the full disclosed reasoning).
 *
 * Idempotency (frozen design §10.1, resolving a 7D/7F contradiction —
 * see agent-7h-security-design-review.md §1.2):
 *   UNIQUE(routing_token_hash, body_hash)
 * written via `INSERT ... ON CONFLICT (routing_token_hash, body_hash)
 * DO NOTHING RETURNING *` (App\Integrations\Services\
 * InboundWebhookReceiptService), never check-then-create. Safe and
 * fully connection-scoped (never provider-wide, never cross-firm)
 * because `webhook_routing_token_hash` on the routing-index table
 * carries a GLOBAL unique constraint — one hash maps to exactly one
 * connection, so `routing_token_hash` here already uniquely identifies
 * a connection without this table needing to say so directly.
 *
 * Registry classification: `Global` in
 * RowLevelSecurityCoverageMappingService::FULL_TABLE_INVENTORY_EXTRA,
 * with an explicit disclaimer note overriding the usual "Global =>
 * no RLS needed" implication — this table's no-RLS posture is a
 * pre-tenant-intake structural property, not an ordinary
 * platform-reference-catalog property. See that file's own updated
 * entry for this table.
 *
 * Retention: 7 days (frozen design §13), columns/index only at
 * Checkpoint 7 — `retention_deadline` is computed at insert time
 * (App\Integrations\Services\InboundWebhookReceiptService), no
 * scheduler/cron/purge job exists yet (shared, disclosed Checkpoint 8+
 * scheduler dependency, matching every prior checkpoint's identical
 * precedent for its own retention columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_webhook_receipts', function (Blueprint $table) {
            $table->id();

            $table->string('provider_key');
            $table->foreign('provider_key', 'integration_webhook_receipts_provider_key_fk')
                ->references('code')->on('integration_providers')->restrictOnDelete();

            $table->string('routing_token_hash', 64);
            $table->string('request_correlation_id')->nullable();
            $table->string('provider_event_id')->nullable();
            $table->string('body_hash', 64);
            $table->string('signature_version')->nullable();

            $table->string('verification_outcome')->default('pending');

            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('provider_timestamp')->nullable();

            $table->string('acknowledgment_status')->default('pending');
            $table->timestamp('acknowledged_at')->nullable();

            $table->string('processing_handoff_status')->default('pending');

            $table->string('failure_code')->nullable();

            $table->timestamp('retention_deadline');

            $table->timestamps();

            $table->unique(['routing_token_hash', 'body_hash']);
            $table->index(['provider_key', 'received_at']);
            $table->index(['verification_outcome', 'received_at']);
            $table->index('retention_deadline');
        });

        // Partial index supporting a future Checkpoint 8 handoff
        // worker's claim query — `verification_outcome`/
        // `processing_handoff_status` are plain strings (no DB enum
        // type, matching every other table in this mission), so this
        // is a Postgres-only DB::statement() partial index, mirroring
        // firm_integrations/integration_credentials/integration_sync_runs'
        // own established DB::statement() partial-index convention
        // (Laravel's fluent $table->index() cannot express a WHERE
        // clause). No claim service consumes this index at Checkpoint
        // 7 (explicitly deferred to Checkpoint 8, frozen design §15) —
        // it is created now purely so the index exists ahead of that
        // future consumer, matching this table's own "columns/index
        // only" retention posture.
        DB::statement(
            'CREATE INDEX integration_webhook_receipts_pending_handoff '.
            'ON integration_webhook_receipts (received_at) '.
            "WHERE verification_outcome = 'verified' AND processing_handoff_status = 'pending'"
        );

        DB::statement(<<<'SQL'
            ALTER TABLE integration_webhook_receipts ADD CONSTRAINT integration_webhook_receipts_ack_consistency CHECK (
                (acknowledgment_status = 'acknowledged' AND acknowledged_at IS NOT NULL)
                OR (acknowledgment_status <> 'acknowledged')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE integration_webhook_receipts ADD CONSTRAINT integration_webhook_receipts_failure_code_required CHECK (
                verification_outcome NOT IN ('signature_invalid', 'routing_unresolved', 'malformed', 'replayed', 'expired', 'error')
                OR failure_code IS NOT NULL
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE integration_webhook_receipts ADD CONSTRAINT integration_webhook_receipts_handoff_requires_verified CHECK (
                processing_handoff_status NOT IN ('handed_off', 'handoff_failed')
                OR verification_outcome = 'verified'
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_webhook_receipts');
    }
};
