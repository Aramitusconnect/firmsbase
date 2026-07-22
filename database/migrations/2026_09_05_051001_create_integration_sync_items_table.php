<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * integration_sync_items — Checkpoint 6, second table of the six-table
 * date block (reviews/checkpoint-06/frozen-design-post-review.md §2/§3/
 * §6/§8). Direct firm-owned, per-run per-object processing-state row.
 * `firm_id` is duplicated directly on this row (not merely inferable
 * via a join through sync_run_id) — the standard convention this whole
 * design uses so FORCE RLS is self-sufficient on every table.
 *
 * `local_type`/`local_id` (NOT `aggregate_type`/`aggregate_id`, NOT
 * `local_resource_type`/`local_resource_id`) — frozen column naming
 * (frozen-design-post-review.md §3, resolving a three-way naming
 * inconsistency across the Checkpoint 6 preparation reports): matches
 * this codebase's one real polymorphic-pointer convention
 * (App\Models\TimelineEvent.subject_type/subject_id,
 * nullableMorphs()). Both nullable — per the frozen domain-model
 * sketch, a sync item's local pointer may not yet be known (e.g. an
 * inbound create not yet materialized). `external_id` is likewise
 * nullable (an outbound-created item may not yet have a provider-
 * assigned id).
 *
 * Idempotency (frozen-design-post-review.md §6): UNIQUE(sync_run_id,
 * external_id), full (non-partial — Postgres treats NULL external_id
 * values as mutually non-conflicting, so items with no external_id
 * yet simply never dedupe against each other, which is the correct
 * behavior). Write mechanism is a raw INSERT ... ON CONFLICT ... DO
 * UPDATE SET attempt_count = attempt_count + 1 (IntegrationSyncItemService)
 * — Laravel's fluent upsert() cannot express the increment.
 *
 * `(firm_id, next_attempt_at) WHERE status = 'failed_retryable'` is
 * the future Checkpoint 8 retry-poller's primary query shape (per the
 * frozen domain-model sketch, verbatim) — Checkpoint 6 ships the
 * index only, no poller.
 *
 * UNIQUE(firm_id, id) is added because `integration_conflicts` (a
 * later migration in this date block) composite-FKs against this
 * table as its (nullable) parent.
 *
 * Retention (frozen-design-post-review.md §10): 60 days from the
 * item's terminal-status timestamp (`terminal_at` below) — the
 * shortest window and highest-volume table of the six. Columns/index
 * only, no purge job at Checkpoint 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_sync_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('sync_run_id'); // bare column; composite FK below is the sole constraint

            $table->string('resource_type');
            $table->string('local_type')->nullable();
            $table->unsignedBigInteger('local_id')->nullable();
            $table->string('external_id')->nullable();

            $table->string('status')->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();

            $table->string('payload_hash')->nullable();
            $table->string('last_error')->nullable();

            $table->timestamp('terminal_at')->nullable();

            $table->timestamps();

            $table->unique(['firm_id', 'id']);
            $table->unique(['sync_run_id', 'external_id']);
            $table->index(['firm_id', 'sync_run_id']);
            $table->index(['firm_id', 'status']);
            $table->index('terminal_at');

            $table->foreign(['firm_id', 'sync_run_id'], 'integration_sync_items_sync_run_fk')
                ->references(['firm_id', 'id'])->on('integration_sync_runs')
                ->cascadeOnDelete();
        });

        DB::statement(
            'CREATE INDEX integration_sync_items_failed_retryable_next_attempt '.
            'ON integration_sync_items (firm_id, next_attempt_at) '.
            "WHERE status = 'failed_retryable'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sync_items');
    }
};
