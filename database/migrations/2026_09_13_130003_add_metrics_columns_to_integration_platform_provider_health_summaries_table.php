<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checkpoint 1 (FirmsVault Live Integrations,
 * checkpoint1-design-health-sandbox.md §A.3.2) — additive metrics
 * columns on `integration_platform_provider_health_summaries`. This
 * table carries no RLS/FORCE RLS at all (see its own create migration's
 * "WHY THIS TABLE HAS NO RLS AND NO FORCE RLS" docblock) — a plain
 * `Schema::table()` migration is all this requires.
 *
 * `total_request_count`/`total_success_count` — summed across every
 * connection's new integration_connection_health columns (see the
 * sibling migration adding those) by
 * App\Services\IntegrationPlatformProviderHealthSummaryService's
 * per-firm-loop accumulator.
 *
 * `throttled_connection_count` — count of connections whose
 * last_failure_category === 'rate_limited', distinct from the existing
 * boolean-ish rate_limit_condition_signal (a derived Healthy/Degraded
 * label, not a count).
 *
 * `token_refresh_failure_count` — connections whose new
 * last_operation_label === 'token_refresh', counted in the same
 * per-firm loop.
 *
 * `webhook_verification_failure_count` — CANNOT come from the
 * per-firm-loop connection scan at all (a rejected inbound webhook
 * frequently cannot be attributed to any resolved connection) — summed
 * instead from the new, platform-owned, no-RLS
 * `integration_webhook_verification_failures` counter table, outside
 * the per-firm loop.
 *
 * `dead_letter_count` — `integration_outbox_events WHERE status =
 * 'dead_lettered'` counted inside the same per-firm loop that already
 * reads firm_integrations for this provider.
 *
 * `avg_latency_ms` — nullable, a simple average over the per-connection
 * last_latency_ms values collected during the same loop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_platform_provider_health_summaries', function (Blueprint $table) {
            $table->unsignedBigInteger('total_request_count')->default(0)->after('firms_requiring_attention_count');
            $table->unsignedBigInteger('total_success_count')->default(0)->after('total_request_count');
            $table->unsignedInteger('throttled_connection_count')->default(0)->after('total_success_count');
            $table->unsignedInteger('token_refresh_failure_count')->default(0)->after('throttled_connection_count');
            $table->unsignedInteger('webhook_verification_failure_count')->default(0)->after('token_refresh_failure_count');
            $table->unsignedInteger('dead_letter_count')->default(0)->after('webhook_verification_failure_count');
            $table->unsignedInteger('avg_latency_ms')->nullable()->after('dead_letter_count');
        });
    }

    public function down(): void
    {
        Schema::table('integration_platform_provider_health_summaries', function (Blueprint $table) {
            $table->dropColumn([
                'total_request_count',
                'total_success_count',
                'throttled_connection_count',
                'token_refresh_failure_count',
                'webhook_verification_failure_count',
                'dead_letter_count',
                'avg_latency_ms',
            ]);
        });
    }
};
