<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * integration_sync_runs.triggering_webhook_event_id — Checkpoint 7,
 * fulfilling Checkpoint 6's disclosed obligation (see that checkpoint's
 * own `create_integration_sync_runs_table` migration docblock:
 * "`triggering_webhook_event_id` is DELIBERATELY OMITTED from this
 * table... Checkpoint 7 adds this column via its own ALTER TABLE in
 * the same wave that creates the referenced table, with a real
 * composite FK declared immediately"). Positioned LAST in the
 * Checkpoint 7 migration block (after both
 * `integration_webhook_receipts` and, critically,
 * `integration_inbound_webhook_events` already exist), per the frozen
 * design's required rollback ordering
 * (reviews/checkpoint-07/frozen-design-post-security-review.md §11).
 *
 * POST-DIFF-REVIEW BUG CLASS, APPLIED PROACTIVELY HERE (not
 * retrofitted): PostgreSQL's composite-key `ON DELETE SET NULL` (no
 * column list) nulls the ENTIRE referencing tuple — including
 * `firm_id` — which violates `firm_id`'s own NOT NULL constraint the
 * moment the referenced event is deleted. This exact bug has already
 * been hit and fixed four times in this codebase's git history for
 * other composite FKs (`integration_sync_runs.retried_run_id`,
 * `integration_outbox_events.firm_integration_id`,
 * `integration_conflicts.sync_item_id`,
 * `integration_conflicts.external_mapping_id` — see each of those
 * migrations' own "POST-DIFF-REVIEW FIX" docblock notes, and the
 * "ON DELETE SET NULL (" grep hits across
 * database/migrations/2026_09_05_050001_create_integration_sync_runs_table.php,
 * database/migrations/2026_09_05_054001_create_integration_conflicts_table.php,
 * and database/migrations/2026_09_05_055001_create_integration_outbox_events_table.php).
 * Fixed here, from the start, via the explicit PostgreSQL 15+
 * column-list `ON DELETE SET NULL (triggering_webhook_event_id)`
 * syntax (confirmed available — this stack runs Postgres 16.14, per
 * every one of those four prior fixes' own confirmation), NOT
 * Laravel's fluent `->nullOnDelete()`, which cannot express a column
 * list and always nulls every column of a composite FK. `firm_id` is
 * never touched by this action.
 *
 * `SET NULL` (not `RESTRICT`): mirrors
 * `integration_outbox_events.firm_integration_id`'s own reasoning
 * (see that migration's docblock) — an
 * `integration_inbound_webhook_events` row is never hard-deleted
 * directly by any code path introduced at this checkpoint (it is
 * mutated in place through its own status lifecycle), so in practice
 * this FK only fires via cascading deletion of an ancestor `firms`/
 * `firm_integrations` row, at which point the referencing
 * `integration_sync_runs` row is either ALSO being cascade-deleted in
 * the same statement (via its own independent `firm_id` -> `firms` FK)
 * or is a genuinely surviving row for a different, unrelated firm —
 * `RESTRICT` would risk the identical cross-path cascade-ordering
 * hazard those four prior fixes already document and reject.
 *
 * `SyncRunService::startRun()` widens with a narrow, additive,
 * backward-compatible optional `?int $triggeringWebhookEventId = null`
 * parameter, included in the existing single `create([...])` call
 * already inside its SAVEPOINT-wrapped transaction — not a second
 * UPDATE after the fact (frozen design §11). Because
 * `IntegrationSyncRun`'s model file is outside this checkpoint's frozen
 * file allowlist, `triggering_webhook_event_id` cannot be added to its
 * `$fillable` array — `SyncRunService::startRun()` therefore uses
 * `Illuminate\Database\Eloquent\Builder::forceCreate()` (mass-assignment
 * -unguarded, Laravel-native) instead of the plain `create()` it used
 * before, so the new column can still be set in the SAME single INSERT
 * without touching the model file. This is a narrow, disclosed
 * implementation decision, not part of the frozen design's literal
 * text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_sync_runs', function (Blueprint $table) {
            $table->foreignId('triggering_webhook_event_id')->nullable()->after('trigger_source');
            $table->index(['firm_id', 'triggering_webhook_event_id']);
        });

        DB::statement(
            'ALTER TABLE integration_sync_runs '.
            'ADD CONSTRAINT integration_sync_runs_triggering_webhook_event_fk '.
            'FOREIGN KEY (firm_id, triggering_webhook_event_id) REFERENCES integration_inbound_webhook_events (firm_id, id) '.
            'ON DELETE SET NULL (triggering_webhook_event_id)'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE integration_sync_runs '.
            'DROP CONSTRAINT integration_sync_runs_triggering_webhook_event_fk'
        );

        Schema::table('integration_sync_runs', function (Blueprint $table) {
            $table->dropIndex(['firm_id', 'triggering_webhook_event_id']);
            $table->dropColumn('triggering_webhook_event_id');
        });
    }
};
