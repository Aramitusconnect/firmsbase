<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requeue support columns — Checkpoint 9 (frozen-design-post-security-
 * review.md §7; agent-9e-requeue-governance.md §5.4/§8;
 * agent-9h-architecture-security-review.md §5.4, explicit sign-off
 * recorded there per the mission's own caution about touching
 * already-shipped tables). Narrow, purely additive migration against
 * two Checkpoint 6 tables — no existing column's type, nullability, or
 * meaning changes, and no existing row's value is touched (every new
 * column defaults cleanly for every pre-existing row).
 *
 * `integration_outbox_events`: `requeue_count` (unsigned int, default
 * 0), `requeued_at` (nullable timestamp), `max_requeues` (unsigned int,
 * default 3 — a platform constant, mirrored by
 * `IntegrationOutboxEventService::DEFAULT_MAX_REQUEUES`). Frozen
 * "attempt-reset design" (§7): `attempts` itself is NEVER reset by
 * `requeue()` — only `max_attempts` is raised by a small fixed
 * increment, so a requeue can never manufacture unbounded retries;
 * `requeue_count`/`max_requeues` independently bound how many times a
 * single row may be requeued AT ALL.
 *
 * `integration_sync_items`: `requeue_count` (unsigned int, default 0),
 * `requeued_at` (nullable timestamp) — no `max_requeues`-equivalent (this
 * table's eligibility is status-gated, not attempts-vs-ceiling-gated;
 * `attempt_count` has no ceiling column to raise).
 *
 * Supporting indexes for the supersession `NOT EXISTS` subquery both
 * primitives use (frozen design §7, agent-9e §8): outbox gets
 * `(firm_id, firm_integration_id, resource_type, resource_id,
 * created_at)`, matching the exact column list agent-9e's guard clause
 * joins against. `integration_sync_items` has no `firm_integration_id`/
 * `resource_id` columns of its own (its parent-scoping column is
 * `sync_run_id`, and its provider-side pointer is `external_id`, not
 * `resource_id`) — the equivalent shape for THIS table's own
 * supersession guard (agent-9e §8: "is there a later sync_run whose own
 * item for the same external_id already reached Succeeded") is
 * `(firm_id, external_id, status)`, which is what
 * `SyncItemService::requeueFromFailedPermanent()`'s own `NOT EXISTS`
 * subquery filters on.
 *
 * Placement: own narrow, separately-reviewable migration — never
 * folded into the `integration_usage_records` create/RLS pair (frozen
 * design §13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_outbox_events', function (Blueprint $table) {
            $table->unsignedInteger('requeue_count')->default(0);
            $table->timestamp('requeued_at')->nullable();
            $table->unsignedInteger('max_requeues')->default(3);

            $table->index(
                ['firm_id', 'firm_integration_id', 'resource_type', 'resource_id', 'created_at'],
                'integration_outbox_events_supersession_lookup'
            );
        });

        Schema::table('integration_sync_items', function (Blueprint $table) {
            $table->unsignedInteger('requeue_count')->default(0);
            $table->timestamp('requeued_at')->nullable();

            $table->index(['firm_id', 'external_id', 'status'], 'integration_sync_items_supersession_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('integration_outbox_events', function (Blueprint $table) {
            $table->dropIndex('integration_outbox_events_supersession_lookup');
            $table->dropColumn(['requeue_count', 'requeued_at', 'max_requeues']);
        });

        Schema::table('integration_sync_items', function (Blueprint $table) {
            $table->dropIndex('integration_sync_items_supersession_lookup');
            $table->dropColumn(['requeue_count', 'requeued_at']);
        });
    }
};
