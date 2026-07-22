<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * integration_sync_cursors — Checkpoint 6, fourth table of the
 * six-table date block (reviews/checkpoint-06/frozen-design-post-review.md
 * §8, agent-6e-sync-run-item-cursor-semantics.md §2-§4). Direct
 * firm-owned. The ONE table among the six that is mutated in place,
 * not append-only — one row per (connection, resource_type,
 * direction), tracking incremental-sync progress for the life of the
 * connection.
 *
 * Natural key includes `sync_direction` (frozen-design-post-review.md
 * §8, closing a real gap in the original three-column domain-model
 * sketch, agent-6e §2): an inbound cursor and an outbound cursor for
 * the same resource_type on the same connection are genuinely
 * independent progress checkpoints and must not share a row.
 *
 * Two-layer concurrency defense (frozen-design-post-review.md §8): this
 * table is Layer 2 — `cursor_version`, an optimistic-concurrency CAS
 * column. Every cursor-advancing UPDATE carries the version it read at
 * claim time (`UPDATE ... WHERE cursor_version = ? RETURNING *`,
 * SyncCursorService); a mismatch rolls back the WHOLE batch
 * transaction, never silently serializes-and-retries. Layer 1 (a
 * partial unique index preventing more than one non-terminal run per
 * scope) lives on `integration_sync_runs` (prior migration in this
 * date block).
 *
 * `cursor_value` is nullable ("no successful sync yet" / reset by
 * cursor invalidation) — a DATA nullability, not an ownership one;
 * `firm_id` itself is never null.
 *
 * Claim/lock protocol (agent-6e §4.3): `locked_by_sync_run_id`/
 * `locked_at` record which run currently holds this cursor, via the
 * same atomic conditional `UPDATE ... WHERE status != 'running' ...
 * RETURNING *` idiom `IntegrationOAuthStateService::claimAndDecrypt()`
 * already establishes for this codebase.
 *
 * Retention: permanent, mutated in place — no retention columns/index
 * (this table is current position state, not a historical record).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_sync_cursors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('firm_integration_id'); // bare column; composite FK below is the sole constraint

            $table->string('resource_type');
            $table->string('sync_direction');

            $table->text('cursor_value')->nullable();
            $table->unsignedBigInteger('cursor_version')->default(0);
            $table->string('status')->default('idle');

            $table->unsignedBigInteger('locked_by_sync_run_id')->nullable();
            $table->timestamp('locked_at')->nullable();

            $table->unsignedInteger('consecutive_failure_count')->default(0);
            $table->timestamp('cursor_issued_at')->nullable();

            $table->timestamps();

            $table->unique(['firm_integration_id', 'resource_type', 'sync_direction']);
            $table->index(['firm_id', 'firm_integration_id']);

            $table->foreign(['firm_id', 'firm_integration_id'], 'integration_sync_cursors_firm_integration_fk')
                ->references(['firm_id', 'id'])->on('firm_integrations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sync_cursors');
    }
};
