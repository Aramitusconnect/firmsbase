<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * integration_platform_overview_summaries — Checkpoint 11 of the Stage B
 * Integration Platform mission ("SuperAdmin Integration Oversight and
 * Governance"; reviews/checkpoint-11/frozen-design-post-security-review.md
 * §5; agent-11h-architecture-security-review.md). The ONE new table this
 * checkpoint introduces: a platform-owned, one-row-per-firm summary
 * table backing the always-visible, cross-firm SuperAdmin overview list,
 * refreshed by a scheduled per-firm job (never a live cross-firm query
 * against any FORCE-RLS tenant table).
 *
 * WHY THIS TABLE HAS NO RLS AND NO FORCE RLS (deliberate, not an
 * oversight — do not "fix" this later without re-reading this note;
 * mirrors integration_webhook_routing_index's own "WHY THIS TABLE HAS NO
 * RLS" docblock convention verbatim, see
 * database/migrations/2026_09_06_060001_create_integration_webhook_routing_index_table.php):
 *   - This table must be readable without a per-request, per-firm RLS
 *     context-switch cost — it backs an always-visible SuperAdmin
 *     overview list over a firm population of undocumented/unbounded
 *     size. A FORCE RLS policy here would make a single cross-firm
 *     overview query structurally impossible without a SECURITY DEFINER
 *     function or a session-GUC-gated carve-out policy — both
 *     explicitly rejected for this mission (frozen design §2 item 4).
 *   - Every column is a sanitized count/status/timestamp, never raw
 *     resource content, a secret, or credential material — there is
 *     nothing here a cross-tenant read could leak beyond "this firm has
 *     N active connections and last synced at time T."
 *   - There is exactly ONE writer:
 *     App\Services\IntegrationPlatformOverviewSummaryService::refreshForFirm(),
 *     an upsert-only sole-writer service invoked exclusively by the
 *     scheduled RefreshIntegrationPlatformOverviewSummaryJob (one job per
 *     activated firm, dispatched by the
 *     integrations:platform-overview:refresh console command).
 *   - `computed_at` is the ONLY staleness signal — this table is a
 *     snapshot, never treated as a live, transactionally-consistent read
 *     of the underlying tenant tables. Any per-firm LIVE drill-down
 *     (connection detail, health, sync history, conflicts, etc.) always
 *     goes through TenantContextService::runWithFirmContext() against
 *     the real, FORCE-RLS-protected tenant tables instead — this
 *     table's data is never treated as authoritative for anything
 *     beyond the overview list's own display.
 *
 * `firm_id` carries a real, NOT NULL foreign key (UNIQUE — one row per
 * firm), cascade-deleted with its parent firm — but, exactly like
 * integration_webhook_routing_index, is exempted from RLS anyway, for
 * the documented reasons above. `firm_uuid` is denormalized (not merely
 * derivable via a join) specifically so the overview list's own read
 * path never needs to touch the `firms` table at all to build a route
 * link to a firm's drill-down page.
 *
 * Registry classification: registered in
 * RowLevelSecurityCoverageMappingService::EXEMPT_TABLES (and the
 * accompanying EXEMPT_TABLE_METADATA / FULL_TABLE_INVENTORY_EXTRA
 * entries) — see that file's own updated entries for this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_platform_overview_summaries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('firm_uuid');

            $table->unsignedInteger('connection_count_active')->default(0);
            $table->unsignedInteger('connection_count_disconnected')->default(0);
            $table->unsignedInteger('connection_count_other')->default(0);

            $table->string('health_summary_state')->nullable();

            $table->string('last_sync_outcome')->nullable();
            $table->timestamp('last_sync_at')->nullable();

            $table->unsignedInteger('failed_permanent_sync_item_count')->default(0);
            $table->unsignedInteger('dead_lettered_outbox_event_count')->default(0);
            $table->unsignedInteger('open_conflict_count')->default(0);

            $table->boolean('entitlement_enabled')->default(false);

            $table->timestamp('computed_at');

            $table->timestamps();

            $table->unique('firm_id');
            $table->index('firm_uuid');

            // Deliberately NO enableRowLevelSecurity() call and NO
            // companion RLS migration for this table — see this
            // migration's class docblock ("WHY THIS TABLE HAS NO RLS
            // AND NO FORCE RLS") for the full, required-reading
            // reasoning.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_platform_overview_summaries');
    }
};
