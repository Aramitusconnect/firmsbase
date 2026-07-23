<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * integration_usage_records — Checkpoint 9 ("Usage, Audit, Retention,
 * Access, and Governance"; frozen-design-post-security-review.md §1/§2;
 * agent-9h-architecture-security-review.md §1). The one new table this
 * checkpoint introduces. Direct firm-owned, raw, APPEND-ONLY, one row
 * per operation — deliberately NOT aggregated (`usage_rollups` already
 * owns aggregation; see 9H §1 for the full "raw vs. aggregated"
 * resolution).
 *
 * `firm_integration_id` is NOT NULL here (unlike
 * `integration_outbox_events.firm_integration_id`, which is nullable) —
 * usage always measures activity against a specific connection.
 * Composite FK `(firm_id, firm_integration_id) REFERENCES
 * firm_integrations(firm_id, id) cascadeOnDelete()`.
 *
 * `provider_key`/`capability`/`operation_type`/`resource_type` are all
 * plain governed strings, never DB/PHP-enforced closed enums — matches
 * `integration_sync_runs.resource_type`'s established precedent exactly
 * (see that table's own create migration: a closed enum would force a
 * core-framework migration every time a future provider/capability adds
 * a new shape). `direction` reuses the EXISTING
 * App\Integrations\Enums\SyncDirection verbatim (stored as its string
 * value) — no second enum is introduced.
 *
 * `occurred_at` (evidence-time) is deliberately distinct from
 * `created_at` (write-time) — a row may legitimately be written after
 * the fact from an already-completed sync/webhook/outbox operation.
 *
 * `correlation_id` is a tracing aid only, never a dedup key — the real
 * dedup key is `idempotency_key` below.
 *
 * Nullable source references — `sync_run_id`, `sync_item_id`,
 * `inbound_webhook_event_id` — are each composite-FK-capable, declared
 * via raw-SQL column-list `ON DELETE SET NULL (<column>)` below,
 * mirroring `integration_outbox_events.firm_integration_id`'s own
 * "POST-DIFF-REVIEW FIX" precedent EXACTLY: Laravel's fluent
 * `nullOnDelete()` cannot express a column list on a composite FK and
 * would null the ENTIRE referencing tuple, including `firm_id`, which
 * is NOT NULL on this table — required, not optional, per frozen design
 * §2. `outbox_event_id` is a genuinely BARE (single-column) FK —
 * `integration_outbox_events` has no `UNIQUE(firm_id, id)` (confirmed:
 * that table's own create migration only has `unique(['firm_id',
 * 'domain_event_id'])`), so a composite FK against it is structurally
 * impossible, not merely inconvenient. Widening
 * `integration_outbox_events`' own schema to work around this is
 * explicitly OUT OF SCOPE (frozen design §2, ruling recorded verbatim
 * in agent-9h-architecture-security-review.md §1.2) — the bare FK here,
 * with Laravel's fluent `nullOnDelete()` (safe on a genuinely
 * single-column FK), is frozen as-is. This creates a disclosed,
 * accepted, already-precedented gap (a firm_id-mismatch race between
 * this row's own `firm_id` and the actual firm_id of the row
 * `outbox_event_id` points at) — the identical accepted-gap class
 * already disclosed for `ai_usage_events.matter_id`.
 *
 * Idempotency (frozen design §2): `UNIQUE(firm_integration_id,
 * idempotency_key)` — leads with `firm_integration_id`, NOT `firm_id`,
 * for the exact reason `integration_inbound_webhook_events`'s own
 * idempotency constraints give (two connections of the same firm must
 * not conflate identically-keyed evidence). Derivation rule (owned by
 * `App\Integrations\Services\IntegrationUsageRecorderService`, not
 * enforced at the schema level): `"{source_type}:{source_id}"`,
 * extended with a documented deterministic suffix (`:{unit}` or
 * `:{capability}`) only when one source operation legitimately produces
 * more than one usage row. Write mechanism: `INSERT ... ON CONFLICT
 * (firm_integration_id, idempotency_key) DO NOTHING RETURNING *`, never
 * check-then-create.
 *
 * `metadata_json` — gated exclusively by
 * App\Integrations\Data\SanitizedUsageMetadataReference — never
 * `$model->toArray()`, never a raw provider response, never
 * `$request->all()`.
 *
 * `retention_deadline` — DELIBERATELY NULLABLE, unlike every sibling
 * table's own `retention_deadline` column (e.g.
 * `integration_inbound_webhook_events.retention_deadline`, which is
 * NOT NULL because that table's retention window has a real, already-
 * shipped default). This table's config key
 * (`integrations.usage_records.retention_days`) ships with NO default
 * (frozen design §2; agent-9h-architecture-security-review.md §6.3,
 * explicitly REJECTING Agent 9A's own 400-day placeholder
 * recommendation in favor of a fail-safe "no default," matching the
 * `oauth_states.unconsumed_expired_retention_hours` precedent) — a
 * NOT NULL column would force
 * `IntegrationUsageRecorderService::recordOnce()` to invent a number at
 * insert time whenever the env var is unset, which is exactly the
 * guessing this checkpoint's frozen design forbids ("any future sweep
 * method must no-op with a disclosed log event on null, never guess a
 * number"). A null `retention_deadline` naturally and correctly
 * excludes a row from any future `retention_deadline <= now()` sweep
 * predicate — the fail-safe behavior is structurally free once the
 * column is nullable, and would be impossible to express cleanly if it
 * were not.
 *
 * `uuid` column: APPROVED per agent-9h-architecture-security-review.md
 * §1.1 (HasPublicUuid) — both closest analogs (`ai_usage_events`,
 * `integration_inbound_webhook_events`) use it, and a firm-facing usage
 * screen is already anticipated
 * (`IntegrationAccessPolicyService::canViewUsage()` exists with no data
 * source today).
 *
 * No billing/cost column of any kind — `quantity`/`unit` is operational
 * evidence, not a charge (frozen design §2).
 *
 * Only `created_at` is a real timestamp column (no `updated_at`) —
 * mirrors `ai_usage_events`'s exact append-only convention (that
 * table's own create migration has `created_at` only, no
 * `$table->timestamps()`), consistent with this table's model-layer
 * `booted()` guard (`updating`/`deleting` both throw `LogicException`)
 * making `updated_at` structurally meaningless.
 *
 * RLS: DirectTenant, combined prepare+force in the companion
 * `2026_09_08_080002_...` migration, canonical policy text copied
 * verbatim from `integration_inbound_webhook_events`'s own migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_usage_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('firm_integration_id'); // bare column; composite FK below is the sole constraint

            $table->string('provider_key');
            $table->string('capability');
            $table->string('operation_type');
            $table->string('direction');
            $table->string('resource_type')->nullable();

            $table->unsignedInteger('quantity')->default(1);
            $table->string('unit');

            $table->string('outcome');

            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->string('correlation_id')->nullable();

            $table->unsignedBigInteger('sync_run_id')->nullable();
            $table->unsignedBigInteger('sync_item_id')->nullable();
            $table->unsignedBigInteger('inbound_webhook_event_id')->nullable();

            $table->unsignedBigInteger('outbox_event_id')->nullable();
            $table->foreign('outbox_event_id')->references('id')->on('integration_outbox_events')->nullOnDelete();

            $table->string('idempotency_key');

            $table->jsonb('metadata_json')->default('{}');

            $table->timestamp('retention_deadline')->nullable();

            $table->unique(['firm_id', 'id']);
            $table->unique(['firm_integration_id', 'idempotency_key']);
            $table->index(['firm_id', 'firm_integration_id']);
            $table->index(['firm_id', 'occurred_at']);
            $table->index(['firm_id', 'capability', 'occurred_at']);
            $table->index(['firm_id', 'operation_type', 'occurred_at']);
            $table->index('retention_deadline');

            $table->foreign(['firm_id', 'firm_integration_id'], 'integration_usage_records_firm_integration_fk')
                ->references(['firm_id', 'id'])->on('firm_integrations')
                ->cascadeOnDelete();
        });

        // sync_run_id / sync_item_id / inbound_webhook_event_id — each a
        // composite-FK-capable, raw-SQL column-list `ON DELETE SET NULL`
        // (never Laravel's fluent nullOnDelete(), which would null the
        // whole tuple including the NOT NULL firm_id column). See this
        // migration's own class docblock for the full reasoning.
        DB::statement(
            'ALTER TABLE integration_usage_records '.
            'ADD CONSTRAINT integration_usage_records_sync_run_fk '.
            'FOREIGN KEY (firm_id, sync_run_id) REFERENCES integration_sync_runs (firm_id, id) '.
            'ON DELETE SET NULL (sync_run_id)'
        );

        DB::statement(
            'ALTER TABLE integration_usage_records '.
            'ADD CONSTRAINT integration_usage_records_sync_item_fk '.
            'FOREIGN KEY (firm_id, sync_item_id) REFERENCES integration_sync_items (firm_id, id) '.
            'ON DELETE SET NULL (sync_item_id)'
        );

        DB::statement(
            'ALTER TABLE integration_usage_records '.
            'ADD CONSTRAINT integration_usage_records_inbound_webhook_event_fk '.
            'FOREIGN KEY (firm_id, inbound_webhook_event_id) REFERENCES integration_inbound_webhook_events (firm_id, id) '.
            'ON DELETE SET NULL (inbound_webhook_event_id)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_usage_records');
    }
};
