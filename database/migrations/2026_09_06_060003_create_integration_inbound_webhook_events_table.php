<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * integration_inbound_webhook_events — Checkpoint 7 (canonical name,
 * WITH the `inbound_` prefix — per agent-7h-security-design-review.md
 * §1.1: this prefix is required specifically to avoid colliding with
 * the pre-existing, unrelated, outbound-only `webhook_events` table,
 * confirmed live at
 * database/migrations/2026_07_21_900002_create_webhook_events_table.php).
 * DirectTenant, standard FORCE ROW LEVEL SECURITY, identical shape to
 * every table since Checkpoint 3. Created only AFTER inbound signature
 * verification has already succeeded (frozen-design-post-security-review.md
 * §10.2) — by the time a row is written, real firm context has already
 * been established via the credential/routing resolution described in
 * that document's §5.
 *
 * `receipt_id` — bare FK to `integration_webhook_receipts.id`,
 * DELIBERATELY NOT a composite FK: the parent table has no `firm_id`
 * column at all (see that table's own create migration), so a
 * composite FK is structurally impossible here, not merely
 * inconvenient. `nullOnDelete()` on a genuinely BARE (single-column)
 * FK is safe to express via Laravel's fluent method — unlike this
 * checkpoint's OTHER composite-FK ON DELETE SET NULL cases (see the
 * `sync_runs.triggering_webhook_event_id` migration's own docblock),
 * nulling a single non-composite column can never touch `firm_id` or
 * violate its NOT NULL constraint, so there is no bug class to guard
 * against here. `NOT NULL` at write time (this checkpoint's write path
 * never creates an event without an already-durable receipt) despite
 * being nullable at the schema level: the receipt table's retention
 * window (7 days) is materially shorter than this table's own, so
 * `RESTRICT` would eventually block routine receipt purging the moment
 * a receipt outlives the event that must still survive — `SET NULL`
 * (via nullOnDelete()) lets the event row survive with a null
 * `receipt_id` once its receipt is eventually pruned by a future
 * Checkpoint 8+ purge job.
 *
 * `provider_key`/`provider_event_id`/`receipt_body_hash` — deliberately
 * DENORMALIZED copies of the corresponding receipt-row values: a
 * firm-scoped session (the only kind of session that can ever read
 * this FORCE-RLS table) cannot read `integration_webhook_receipts` at
 * all (that table has no RLS predicate that would ever match a
 * firm-scoped session in the first place — see that table's own
 * docblock), so this table must carry its own copies of anything a
 * firm-facing activity view needs to display.
 *
 * Idempotency — TWO independent constraints (frozen design §10.2,
 * agent-7h-security-design-review.md §1.4, a REQUIRED OVERRIDE of the
 * Checkpoint 0 spec's literal §16 text, not a design choice left
 * open):
 *   UNIQUE(receipt_id)
 *   UNIQUE(firm_integration_id, provider_key, provider_event_id)
 * `UNIQUE(firm_integration_id, provider_event_id)` — NOT
 * `UNIQUE(provider_id, provider_event_id)` (the frozen spec's literal
 * §16 text) and NOT `UNIQUE(firm_id, provider_event_id)` either: both
 * of those would fail to prevent two DIFFERENT connections (of the
 * same firm, or of different firms) from conflating identical
 * provider-minted event ids. Verified directly against
 * `integration_external_mappings`' own proven precedent (both of that
 * table's unique indexes lead with `firm_integration_id`, specifically
 * to prevent two connections of the SAME firm from conflating
 * identical provider-minted ids — see
 * database/migrations/2026_09_05_052001_create_integration_external_mappings_table.php).
 * `UNIQUE(firm_id, id)` is also added, mirroring
 * `integration_sync_runs`/`firm_integrations`' own precedent, so a
 * future table could composite-FK against this one if ever needed.
 * Write path: `INSERT ... ON CONFLICT (firm_integration_id,
 * provider_key, provider_event_id) DO NOTHING RETURNING *`
 * (App\Integrations\Services\InboundWebhookEventService), never
 * check-then-create.
 *
 * `payload_reference_json` — sanitized allowlist metadata/reference
 * ONLY, matching the SAME `SanitizedPayloadReference`-shaped discipline
 * `integration_outbox_events.payload_json` already established
 * (App\Integrations\Data\SanitizedPayloadReference) — NEVER a raw
 * provider body, never `$request->all()`, never a raw JSON dump.
 * `payload_hash` is sha256 over this already-sanitized reference, not
 * over the raw provider payload.
 *
 * `status` — 5-state, mirrors `OutboxEventStatus`'s exact convention
 * (App\Integrations\Enums\WebhookInboundEventStatus): verified,
 * handed_off, processed, failed, skipped. `lock_token`/`locked_at` are
 * set on claim — Checkpoint 7 ships only the guarded single-statement
 * claim/complete/fail SQL *shape* as a specification (mirroring
 * `IntegrationOutboxEventService`), not a pooled multi-row
 * SKIP-LOCKED claim mechanism (explicitly deferred to Checkpoint 8,
 * frozen design §15).
 *
 * `triggering_sync_run_id` — forward pointer, complements
 * `integration_sync_runs.triggering_webhook_event_id` (added by this
 * checkpoint's own
 * 2026_09_06_060005_add_triggering_webhook_event_id_to_integration_sync_runs_table
 * migration) — no FK is declared for this column at Checkpoint 7 (no
 * writer of it exists yet; a future checkpoint that starts writing it
 * is responsible for adding the appropriate composite FK at that time).
 *
 * RLS (canonical, standard — frozen design §10.2, copied byte-for-byte
 * from `integration_oauth_states`/`integration_credentials`/every
 * Checkpoint 3-6 table's own combined prepare+force migration; see
 * the companion
 * 2026_09_06_060004_prepare_row_level_security_and_force_rls_on_integration_inbound_webhook_events_table
 * migration): `firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint`,
 * both `USING` and `WITH CHECK`, FORCE-enforced. No self-lookup or
 * bootstrap carve-out — by the time a row is written, real firm
 * context has already been established via the credential/routing
 * resolution (frozen design §5), unlike `integration_oauth_states`,
 * which genuinely needs its self-lookup carve-out to bootstrap an
 * OAuth callback BEFORE any firm context can be known.
 *
 * Retention: longer window than the receipt's (frozen design §13,
 * `config('integrations.webhook.event_redact_after_days', 400)` /
 * `config('integrations.webhook.event_delete_after_days', 2555)`), a
 * new, disclosed, configurable placeholder — NOT derived from a seeded
 * `RetentionPolicy` default (that system has no production seeder, no
 * config default, and no `RetentionRecordType` case for any
 * integration/webhook/audit_log category — confirmed by
 * agent-7h-security-design-review.md §1.8; nothing is added to
 * `RetentionRecordType`/`RetentionPolicyService` by this checkpoint).
 * `retention_deadline` computed at insert time
 * (App\Integrations\Services\InboundWebhookEventService). Columns/index
 * only at Checkpoint 7, no scheduler/cron/purge job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_inbound_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('firm_integration_id'); // bare column; composite FK below is the sole constraint

            $table->unsignedBigInteger('receipt_id')->nullable();
            $table->foreign('receipt_id')->references('id')->on('integration_webhook_receipts')->nullOnDelete();

            $table->string('provider_key');
            $table->string('provider_event_id')->nullable();
            $table->string('receipt_body_hash', 64)->nullable();

            $table->string('event_type')->nullable();

            $table->jsonb('payload_reference_json')->default('{}');
            $table->string('payload_hash', 64)->nullable();

            $table->string('status')->default('verified');
            $table->string('lock_token')->nullable();
            $table->timestamp('locked_at')->nullable();

            $table->unsignedInteger('processing_attempts')->default(0);

            $table->string('failure_code')->nullable();
            $table->string('failure_detail')->nullable();

            $table->unsignedBigInteger('triggering_sync_run_id')->nullable();

            $table->timestamp('received_at');
            $table->timestamp('started_processing_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('terminal_at')->nullable();

            $table->timestamp('retention_deadline');

            $table->timestamps();

            $table->unique(['firm_id', 'id']);
            $table->unique('receipt_id');
            $table->unique(['firm_integration_id', 'provider_key', 'provider_event_id']);

            $table->index(['firm_id', 'firm_integration_id']);
            $table->index(['firm_id', 'status']);
            $table->index(['firm_id', 'received_at']);
            $table->index('terminal_at');
            $table->index('retention_deadline');

            $table->foreign(['firm_id', 'firm_integration_id'], 'integration_inbound_webhook_events_firm_integration_fk')
                ->references(['firm_id', 'id'])->on('firm_integrations')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE integration_inbound_webhook_events ADD CONSTRAINT integration_inbound_webhook_events_processed_requires_timestamp CHECK (
                status <> 'processed' OR processed_at IS NOT NULL
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE integration_inbound_webhook_events ADD CONSTRAINT integration_inbound_webhook_events_terminal_requires_terminal_at CHECK (
                status NOT IN ('processed', 'failed', 'skipped') OR terminal_at IS NOT NULL
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE integration_inbound_webhook_events ADD CONSTRAINT integration_inbound_webhook_events_failed_requires_failure_code CHECK (
                status <> 'failed' OR failure_code IS NOT NULL
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE integration_inbound_webhook_events ADD CONSTRAINT integration_inbound_webhook_events_handoff_lock_consistency CHECK (
                (status = 'handed_off' AND lock_token IS NOT NULL AND locked_at IS NOT NULL)
                OR (status <> 'handed_off')
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_inbound_webhook_events');
    }
};
