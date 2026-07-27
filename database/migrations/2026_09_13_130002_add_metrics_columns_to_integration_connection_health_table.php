<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checkpoint 1 (FirmsVault Live Integrations,
 * checkpoint1-design-health-sandbox.md §A.3.1) — additive metrics
 * columns on `integration_connection_health`. Plain `Schema::table()`
 * migration: this table already carries FORCE ROW LEVEL SECURITY
 * (database/migrations/2026_09_07_070002_prepare_row_level_security_and_force_rls_on_integration_connection_health_table.php),
 * and adding columns to an already-FORCE-RLS'd table needs no new
 * prepare/force migration of its own.
 *
 * `total_request_count`/`total_success_count` — cumulative counters,
 * incremented on every App\Integrations\Services\HealthStateService
 * record*() call (never reset, unlike the existing
 * `consecutive_failures` streak counter). Deliberately NOT storing a
 * derived success_rate column — computed at read time
 * (total_success_count / total_request_count) so it can never drift
 * out of sync with its inputs, mirroring this table's own existing
 * "summary_state is never independently settable" discipline.
 *
 * `last_operation_label` — persists the SAME
 * App\Integrations\Data\SanitizedHealthDiagnostic::$operationLabel
 * value every record*Error()/recordRateLimited() call already receives
 * today (folded only into the free-text sanitized_diagnostic_summary
 * template until now) — a closed vocabulary (health_check|
 * token_refresh|pull_sync|push_sync|webhook_process|outbox_dispatch),
 * never provider-supplied free text. Nullable: recordSuccess() has no
 * diagnostic/operation-label parameter of its own today; Checkpoint 1
 * does not widen recordSuccess()'s signature to add one (out of scope
 * — the caller-supplied $latencyMs parameter added by this same
 * checkpoint is the only new recordSuccess() parameter).
 *
 * `last_latency_ms`/`last_sync_lag_seconds` — both nullable, populated
 * only when the calling code path actually measured the relevant
 * duration (the shared outbound HTTP call path a parallel Checkpoint 1
 * workstream is building for `last_latency_ms`; PullSyncJob/PushSyncJob
 * for `last_sync_lag_seconds` — neither wired by this migration itself,
 * which only adds the columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_connection_health', function (Blueprint $table) {
            $table->unsignedBigInteger('total_request_count')->default(0)->after('sanitized_diagnostic_summary');
            $table->unsignedBigInteger('total_success_count')->default(0)->after('total_request_count');
            $table->string('last_operation_label')->nullable()->after('total_success_count');
            $table->unsignedInteger('last_latency_ms')->nullable()->after('last_operation_label');
            $table->unsignedInteger('last_sync_lag_seconds')->nullable()->after('last_latency_ms');
        });
    }

    public function down(): void
    {
        Schema::table('integration_connection_health', function (Blueprint $table) {
            $table->dropColumn([
                'total_request_count',
                'total_success_count',
                'last_operation_label',
                'last_latency_ms',
                'last_sync_lag_seconds',
            ]);
        });
    }
};
