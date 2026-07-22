<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * integration_sync_runs — Checkpoint 6 of the Stage B integration-
 * platform mission ("Transactional Outbox and Sync Persistence
 * Foundation"; reviews/checkpoint-06/frozen-design-post-review.md §2/§4/
 * §8). Direct firm-owned, first table of the six-table Checkpoint 6
 * date block (2026_09_05_050xxx-055xxx). Represents one attempt to pull
 * or push a resource_type/direction pair against a firm_integrations
 * connection — the audit-header row `integration_sync_items` rows
 * belong to.
 *
 * `resource_type`/`sync_direction`/`run_type`/`trigger_source`/`status`
 * are all plain string columns cast to a backed PHP enum at the model
 * layer (App\Integrations\Enums\SyncRunType/SyncTriggerSource/
 * SyncRunStatus) — mirrors `firm_integrations.status`'s exact
 * convention, no DB-level enum type introduced. `resource_type` is
 * deliberately NOT one of the new Checkpoint 6 enums: per
 * agent-6e-sync-run-item-cursor-semantics.md §6, provider-declared
 * resource shapes differ per provider in a way that would force a
 * core-framework migration every time a future provider adds a
 * resource type, so it is a governed string validated at the
 * capability-contract layer, not a closed enum. `sync_direction`
 * reuses the EXISTING App\Integrations\Enums\SyncDirection class
 * (Checkpoint 1, 3 cases: Inbound/Outbound/Bidirectional) verbatim —
 * this migration does not, and must not, define a second one.
 *
 * `triggering_webhook_event_id` is DELIBERATELY OMITTED from this
 * table (frozen-design-post-review.md §9): it would reference
 * `integration_inbound_webhook_events`, a Checkpoint-7-only table that
 * does not exist yet — a Schema::create() cannot declare an FK
 * (bare or composite) against a table that has not been created, and a
 * generic unconstrained placeholder column is explicitly rejected as
 * worse than omission (unenforced writes for the entire Checkpoint
 * 6-7 interim). Checkpoint 7 adds this column via its own ALTER TABLE
 * in the same wave that creates the referenced table, with a real
 * composite FK declared immediately. `App\Integrations\Enums\SyncTriggerSource`
 * (below) already records WHY a run started (Webhook is one of six
 * cases) without needing a pointer to the specific triggering row.
 *
 * `retried_run_id` — self-referencing composite FK (firm_id,
 * retried_run_id) REFERENCES integration_sync_runs(firm_id, id),
 * nullOnDelete(). Composite (not bare) is achievable and required here
 * because parent and child are the SAME table, sharing the
 * UNIQUE(firm_id, id) added below — unlike the firm_users bare-FK
 * cases elsewhere in this checkpoint, there is no structural obstacle.
 * nullOnDelete() rather than restrictOnDelete(): once the referenced
 * failed run is eventually pruned (this table's own 180-day retention
 * window, columns/index only — no purge job at Checkpoint 6), the
 * retry-linking row must not become undeletable or throw; the retry
 * row itself survives with retried_run_id simply nulled.
 *
 * UNIQUE(firm_id, id) is added because `integration_sync_items` (next
 * migration in this date block) composite-FKs against this table as
 * its parent — mirrors firm_integrations' own UNIQUE(firm_id, id)
 * precedent, added for the identical reason at Checkpoint 3.
 *
 * Concurrency (frozen-design-post-review.md §8, agent-6e §4): a
 * partial unique index enforces "at most one non-terminal run per
 * (firm_integration, resource_type, direction)" — Layer 1 of this
 * checkpoint's two-layer cursor-concurrency defense (Layer 2 is
 * `integration_sync_cursors.cursor_version`, next-next migration).
 * Postgres-only DB::statement() partial-index syntax, matching
 * firm_integrations/integration_credentials' own established
 * DB::statement() partial-index convention (Laravel's fluent
 * $table->unique() cannot express a WHERE clause).
 *
 * Retention (frozen-design-post-review.md §10): 180 days from
 * finished_at, columns/index only — no cleanup job/command/scheduler
 * entry implemented at Checkpoint 6 (shared, disclosed Checkpoint 8
 * scheduler dependency, matching Checkpoint 5's identical precedent
 * for integration_oauth_states).
 *
 * POST-DIFF-REVIEW FIX (checkpoint-06 verification pass) —
 * integration_sync_runs_retried_run_fk composite-FK ON DELETE SET NULL
 * bug: PostgreSQL's composite-key `ON DELETE SET NULL` (no column
 * list) nulls the ENTIRE referencing tuple — including firm_id — which
 * violates firm_id's own NOT NULL constraint the moment the referenced
 * run is deleted, exactly backwards from the "the retry-linking row
 * simply loses its retried_run_id, never becomes undeletable" intent
 * documented above. Fixed by declaring the composite FK via a raw
 * ALTER TABLE ... ON DELETE SET NULL (retried_run_id) statement
 * (PostgreSQL 15+ column-list syntax; confirmed available on this
 * stack's Postgres 16.14) instead of Laravel's fluent ->nullOnDelete(),
 * which cannot express a column list and always nulls every column of
 * a composite FK. firm_id is never touched by this action. A bare-FK +
 * compensating `saving`-listener downgrade was deliberately NOT used
 * here: this is a self-referencing composite FK with no structural
 * obstacle to a full composite FK (both sides share the SAME
 * UNIQUE(firm_id, id) added below), so keeping the full composite FK
 * (DB-enforced same-firm validation at INSERT/UPDATE time, including
 * for raw DB::table()->insert() writes that bypass Eloquent entirely)
 * is strictly stronger than a bare FK ever could be, and requires no
 * edit to app/Integrations/Models/IntegrationSyncRun.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_sync_runs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('firm_integration_id'); // bare column; composite FK below is the sole constraint

            $table->string('resource_type');
            $table->string('sync_direction');
            $table->string('run_type');
            $table->string('trigger_source');
            $table->string('status')->default('pending');

            $table->foreignId('retried_run_id')->nullable(); // bare column; composite self-FK below is the sole constraint

            $table->timestamp('cancel_requested_at')->nullable();

            $table->unsignedInteger('items_total')->default(0);
            $table->unsignedInteger('items_succeeded')->default(0);
            $table->unsignedInteger('items_failed')->default(0);
            $table->unsignedInteger('items_skipped')->default(0);

            $table->string('error_summary')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->unique(['firm_id', 'id']);
            $table->index(['firm_id', 'firm_integration_id']);
            $table->index(['firm_id', 'status']);
            $table->index(['firm_id', 'started_at']);
            $table->index('finished_at');

            $table->foreign(['firm_id', 'firm_integration_id'], 'integration_sync_runs_firm_integration_fk')
                ->references(['firm_id', 'id'])->on('firm_integrations')
                ->cascadeOnDelete();

            // retried_run_id's composite self-FK is declared below via raw
            // DB::statement() with an explicit ON DELETE SET NULL (<column>)
            // list — see this migration's class docblock
            // ("POST-DIFF-REVIEW FIX") for why the fluent ->nullOnDelete()
            // (which nulls the WHOLE composite tuple, including firm_id,
            // violating its NOT NULL constraint) is not used here.
        });

        DB::statement(
            'ALTER TABLE integration_sync_runs '.
            'ADD CONSTRAINT integration_sync_runs_retried_run_fk '.
            'FOREIGN KEY (firm_id, retried_run_id) REFERENCES integration_sync_runs (firm_id, id) '.
            'ON DELETE SET NULL (retried_run_id)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX integration_sync_runs_one_active_per_scope '.
            'ON integration_sync_runs (firm_id, firm_integration_id, resource_type, sync_direction) '.
            "WHERE status IN ('pending', 'running')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sync_runs');
    }
};
