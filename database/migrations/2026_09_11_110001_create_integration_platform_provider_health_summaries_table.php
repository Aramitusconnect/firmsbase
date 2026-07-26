<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * integration_platform_provider_health_summaries — Phase 2 of the
 * FirmsVault Platform Admin Control Center mission ("Integration
 * Operations Center"). Mirrors integration_platform_overview_summaries'
 * exact shape/rationale (see that table's own create migration,
 * database/migrations/2026_09_09_090001_create_integration_platform_overview_summaries_table.php),
 * one grain narrower: this table is one row PER PROVIDER (a rollup
 * across every activated firm's connections to that provider), never
 * one row per firm.
 *
 * WHY THIS TABLE HAS NO RLS AND NO FORCE RLS (deliberate, not an
 * oversight — do not "fix" this later without re-reading this note;
 * mirrors integration_platform_overview_summaries' and
 * integration_providers' own "WHY THIS TABLE HAS NO RLS" docblocks):
 *   - Unlike integration_platform_overview_summaries (which carries a
 *     genuine NOT NULL, UNIQUE firm_id column despite being exempt),
 *     this table carries NO firm_id column at all and structurally
 *     never should — it is a per-provider, cross-firm rollup, not a
 *     per-firm row. It is exactly as tenant-blind as
 *     `integration_providers` itself (the table its own
 *     `integration_provider_id`/`provider_code` columns identify — a
 *     deliberately soft reference, not a hard FK; see the column
 *     definition below for why).
 *   - It backs the always-visible, cross-firm SuperAdmin Provider
 *     Health view over a firm population of undocumented/unbounded
 *     size — a FORCE RLS policy would be structurally meaningless here
 *     even if attempted (there is no firm_id to scope a policy
 *     predicate against).
 *   - Every column is a sanitized count/status/timestamp snapshot —
 *     connection counts, a derived oauth/webhook/rate-limit health
 *     signal, a recent-error-classification count summary, and a
 *     computed_at staleness marker — never raw resource content, a
 *     secret, or credential material of any kind.
 *   - There is exactly ONE writer:
 *     App\Services\IntegrationPlatformProviderHealthSummaryService::refreshForProvider(),
 *     an upsert-only sole-writer service invoked exclusively by the
 *     scheduled RefreshIntegrationPlatformProviderHealthSummaryJob (one
 *     job per provider, dispatched by the
 *     integrations:platform-provider-health:refresh console command).
 *     That service computes its aggregate by iterating every activated
 *     firm's OWN tenant context via
 *     TenantContextService::runWithFirmContext() — reading each firm's
 *     real, FORCE-RLS-protected `firm_integrations`/
 *     `integration_connection_health`/`integration_webhook_routing_index`
 *     rows one firm at a time, exactly like
 *     IntegrationPlatformOverviewSummaryService::refreshForFirm() does
 *     — NEVER a live, unscoped cross-firm query. Only the FINAL upsert
 *     into this no-RLS table happens outside any tenant context, since
 *     this table itself needs none.
 *   - `computed_at` is the ONLY staleness signal — this table is a
 *     snapshot, never treated as a live, transactionally-consistent
 *     read of the underlying tenant tables.
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
        Schema::create('integration_platform_provider_health_summaries', function (Blueprint $table) {
            $table->id();

            // Deliberately NOT a real ->foreignId()->constrained() FK
            // into integration_providers (unlike, e.g.,
            // firm_integrations' own real FK into it) — a plain
            // unsignedBigInteger column instead. Two independent
            // reasons:
            //   1. integration_providers is small, static, seeded-only
            //      reference data (see its own create migration) that
            //      this mission never deletes rows from — there is no
            //      real orphan risk a hard FK would meaningfully guard
            //      against here.
            //   2. IntegrationProviderTest's own migration up/down/
            //      rollback-and-reapply round-trip tests
            //      (test_migration_down_and_up_restores_exact_prior_state /
            //      test_migration_rollback_and_reapplication_restores_exact_prior_state)
            //      already enumerate, by exact migration path and in a
            //      carefully frozen order, EVERY real FK dependent of
            //      integration_providers that must be torn down first —
            //      a hard FK here would require inserting this table
            //      into that already-elaborate, historically-frozen
            //      sequence for no real integrity benefit given (1).
            // `provider_code` (below) is the reliable, denormalized
            // identifier this table's own readers/writers actually use
            // — mirrors integration_platform_overview_summaries'
            // firm_uuid denormalization rationale exactly.
            // `integration_provider_id` still carries a genuine unique
            // index (declared as its own explicit $table->unique() call
            // below — see writeSummaryRow()'s upsert(uniqueBy: [...])
            // requirement).
            $table->unsignedBigInteger('integration_provider_id');
            $table->string('provider_code');

            $table->boolean('provider_enabled')->default(false);

            $table->unsignedInteger('connected_firm_count')->default(0);
            $table->unsignedInteger('disconnected_firm_count')->default(0);
            $table->unsignedInteger('firms_requiring_attention_count')->default(0);

            $table->string('oauth_health_signal')->nullable();
            $table->string('webhook_health_signal')->nullable();
            $table->string('rate_limit_condition_signal')->nullable();

            $table->json('recent_error_classification_summary')->nullable();

            $table->timestamp('computed_at');

            $table->timestamps();

            $table->unique('integration_provider_id', 'iphps_provider_id_unique');
            $table->index('provider_code', 'iphps_provider_code_index');

            // Deliberately NO enableRowLevelSecurity() call and NO
            // companion RLS migration for this table — see this
            // migration's class docblock ("WHY THIS TABLE HAS NO RLS
            // AND NO FORCE RLS") for the full, required-reading
            // reasoning.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_platform_provider_health_summaries');
    }
};
