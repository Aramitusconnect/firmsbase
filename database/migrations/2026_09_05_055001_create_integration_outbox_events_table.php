<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * integration_outbox_events — Checkpoint 6, sixth and final table of
 * the six-table date block (reviews/checkpoint-06/frozen-design-post-review.md
 * §6/§7/§11; agent-6d-outbox-claiming.md). Direct firm-owned. The
 * transactional-outbox row: written INSIDE the same DB transaction as
 * its triggering business write (never via DB::afterCommit(), which
 * every existing "durable event" mechanism in this codebase uses and
 * which reproduces exactly the dual-write/crash-loss gap this table
 * exists to close — see agent-6a-event-transaction-queue-inventory.md).
 *
 * `firm_integration_id` is nullable — not every internal async event
 * is tied to a specific provider connection. Composite FK still
 * applies when non-null (Postgres MATCH SIMPLE semantics: a NULL
 * referencing column passes the FK trivially).
 *
 * `domain_event_id` — native Postgres `uuid` type via $table->uuid(),
 * matching firm_integrations.uuid's confirmed convention (frozen-design-
 * post-review.md §3.5/§13, independently verified against the live
 * migration rather than assumed). Minted ONCE by the caller, in
 * application code, BEFORE the triggering transaction begins — never
 * regenerated on retry of the same logical unit of work — so a retried
 * caller passing the SAME domain_event_id is a safe no-op, never a
 * duplicate row.
 *
 * Idempotency (frozen-design-post-review.md §6): UNIQUE(firm_id,
 * domain_event_id), full (non-partial) — a completed or dead-lettered
 * row must still permanently block a duplicate insert for the same
 * domain event; re-processing goes through this SAME row's attempt
 * columns, never a second row. Write via insertOrIgnoreReturning() +
 * re-SELECT fallback (IntegrationOutboxEventService::recordOnce()).
 *
 * Payload minimization (frozen-design-post-review.md §11): `payload_json`
 * stores ONLY SanitizedPayloadReference::toArray() — never a raw
 * Eloquent Model or $model->toArray(). `payload_hash` is sha256 over
 * the already-sanitized reference, never over raw model state.
 *
 * States (5, not 7 — frozen-design-post-review.md §7): pending,
 * processing, completed, dead_lettered, cancelled. `retry_scheduled`
 * is folded into pending + next_attempt_at; `failed` is folded into an
 * immediate resolved transition (pending-for-retry or dead_lettered) —
 * neither is a separate resting state.
 *
 * CHECK constraint: a `processing` row always has both a non-null
 * lock_token and locked_at — a DB-level guarantee, not merely an
 * aspiration of the service layer.
 *
 * Claim mechanism (frozen-design-post-review.md §7, exact SQL — see
 * IntegrationOutboxEventService::claim()): a single `UPDATE ... WHERE
 * id IN (SELECT ... FOR UPDATE SKIP LOCKED) RETURNING *` statement,
 * never a bare SELECT followed by a separate UPDATE. The 15-minute
 * stale-lock bound is folded into the SAME predicate (no second
 * reclaim mechanism) and is config-driven
 * (config('integrations.outbox.stale_lock_minutes'), default 15 — see
 * IntegrationOutboxEventService for the inline default, since
 * config/integrations.php is outside this checkpoint's frozen file
 * allowlist and is not modified here).
 *
 * Retention (frozen-design-post-review.md §10): completed 30d,
 * dead_lettered 90d, cancelled 30d, each from its own terminal
 * timestamp. Columns/index only, no purge job at Checkpoint 6.
 *
 * POST-DIFF-REVIEW FIX (checkpoint-06 verification pass) —
 * integration_outbox_events_firm_integration_fk on-delete behavior:
 *
 * Bug: the composite FK's fluent ->nullOnDelete() (no column list)
 * nulls the ENTIRE referencing tuple on delete of the parent
 * firm_integrations row, including firm_id — violating firm_id's own
 * NOT NULL constraint. Fixed, as for the other 3 affected constraints
 * in this checkpoint, via a raw ALTER TABLE ... ON DELETE SET NULL
 * (firm_integration_id) statement (PostgreSQL 15+ column-list syntax)
 * so only firm_integration_id is ever nulled, never firm_id.
 *
 * On-delete-behavior choice (SET NULL, not RESTRICT) — considered and
 * rejected RESTRICT deliberately, not by default pattern-matching the
 * other 3 constraints:
 *
 * RESTRICT ("block deleting a firm_integration that still has
 * unclaimed/in-flight outbox events referencing it") sounds like the
 * operationally safer choice at first read, but two facts about this
 * codebase make it the WRONG choice here:
 *
 * 1. firm_integrations rows are never hard-deleted directly anywhere
 *    in this codebase today (confirmed: no `FirmIntegration::destroy()`
 *    / `->delete()` call exists anywhere in app/; disconnect is a
 *    ProviderConnectionService-owned `status` transition to
 *    ConnectionStatus::Disconnected, never a row deletion). The ONLY
 *    path that ever deletes a firm_integrations row is the cascading
 *    deletion of its parent `firms` row
 *    (firm_integrations.firm_id -> firms, cascadeOnDelete()) — e.g. a
 *    firm-level account-deletion flow.
 * 2. RESTRICT is actively dangerous on exactly that one real deletion
 *    path. When a `firms` row is deleted, integration_outbox_events
 *    rows for that firm are ALSO independently cascade-deleted via
 *    their own direct firm_id -> firms FK (cascadeOnDelete(), see
 *    below) — in the SAME statement, but not necessarily processed
 *    before firm_integrations' own firm_id -> firms cascade fires and
 *    triggers this table's firm_integration_id RESTRICT check.
 *    PostgreSQL gives no ordering guarantee across independent cascade
 *    paths converging on the same doomed rows, so a RESTRICT here risks
 *    a spurious "update or delete on table firm_integrations violates
 *    foreign key constraint" failure on ordinary firm deletion even
 *    though every affected outbox_events row is, in the same
 *    transaction, already being deleted anyway. A plain FK RESTRICT
 *    also cannot be scoped to "only unclaimed/in-flight (pending/
 *    processing) rows" — it would block on ANY surviving referencing
 *    row regardless of terminal status, and with no purge job yet at
 *    Checkpoint 6 (frozen-design-post-review.md §10), completed/
 *    dead-lettered/cancelled rows can linger for their full 30-90 day
 *    retention window, making RESTRICT's blast radius far wider than
 *    "in-flight events" in practice.
 *
 * SET NULL avoids both problems: it never introduces the cross-path
 * RESTRICT-vs-cascade ordering hazard, and firm_integration_id is
 * already documented above as nullable/optional ("not every internal
 * async event is tied to a specific provider connection"), so an
 * event surviving with a null firm_integration_id after its connection
 * is gone is consistent with an already-supported state, not a new
 * one. If a future checkpoint introduces a genuine standalone "delete
 * this one firm_integration without deleting the whole firm"
 * operation, THAT operation's own service-layer code is the right
 * place to check for/drain unclaimed or in-flight outbox events first
 * — an application-level guard, not a DB-level RESTRICT, given the
 * ordering hazard demonstrated above.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_outbox_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->unsignedBigInteger('firm_integration_id')->nullable(); // bare column; composite FK below

            $table->uuid('domain_event_id');
            $table->string('event_type');
            $table->string('resource_type')->nullable();
            $table->string('resource_id')->nullable();

            $table->jsonb('payload_json')->default('{}');
            $table->string('payload_hash')->nullable();

            $table->string('status')->default('pending');
            $table->uuid('lock_token')->nullable();
            $table->timestamp('locked_at')->nullable();

            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts');
            $table->timestamp('next_attempt_at')->useCurrent();

            $table->string('last_error')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('dead_lettered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->unique(['firm_id', 'domain_event_id']);
            $table->index(['firm_id', 'status', 'next_attempt_at']);
            $table->index(['firm_id', 'firm_integration_id']);
            $table->index('completed_at');
            $table->index('dead_lettered_at');
            $table->index('cancelled_at');

            // firm_integration_id's composite FK is declared below via raw
            // DB::statement() with an explicit ON DELETE SET NULL (<column>)
            // list — see this migration's class docblock
            // ("POST-DIFF-REVIEW FIX") for why the fluent ->nullOnDelete()
            // (which nulls the WHOLE composite tuple, including firm_id,
            // violating its NOT NULL constraint) is not used here, and for
            // why SET NULL (not RESTRICT) is the correct on-delete action.
        });

        DB::statement(
            'ALTER TABLE integration_outbox_events '.
            'ADD CONSTRAINT integration_outbox_events_firm_integration_fk '.
            'FOREIGN KEY (firm_id, firm_integration_id) REFERENCES firm_integrations (firm_id, id) '.
            'ON DELETE SET NULL (firm_integration_id)'
        );

        DB::statement(<<<'SQL'
            ALTER TABLE integration_outbox_events ADD CONSTRAINT integration_outbox_events_processing_lock_consistency CHECK (
                (status = 'processing' AND lock_token IS NOT NULL AND locked_at IS NOT NULL)
                OR (status <> 'processing')
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_outbox_events');
    }
};
