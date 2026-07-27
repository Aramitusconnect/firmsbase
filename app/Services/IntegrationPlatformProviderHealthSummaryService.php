<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FirmActivationStatus;
use App\Integrations\Data\SanitizedHealthDiagnostic;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\HealthSummaryState;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Services\HealthStateService;
use App\Models\Firm;
use Illuminate\Support\Facades\DB;

/**
 * IntegrationPlatformProviderHealthSummaryService — Phase 2 of the
 * FirmsVault Platform Admin Control Center mission ("Integration
 * Operations Center"). The ONE, sole writer of
 * `integration_platform_provider_health_summaries` — an upsert-only
 * refresh, never a partial/incremental update. Called exclusively by
 * App\Jobs\RefreshIntegrationPlatformProviderHealthSummaryJob (one job
 * per provider, scheduled via the
 * `integrations:platform-provider-health:refresh` console command).
 *
 * refreshForProvider() computes its aggregate by iterating every
 * ACTIVATED firm and reading that firm's real, FORCE-RLS-protected
 * tenant tables (`firm_integrations`, `integration_connection_health`,
 * `integration_webhook_routing_index`) one firm at a time, each inside
 * its own TenantContextService::runWithFirmContext() call — exactly
 * mirroring IntegrationPlatformOverviewSummaryService::refreshForFirm()'s
 * per-firm-context discipline. This is structurally required, never
 * optional: `integration_connection_health` is FORCE-RLS'd per firm, so
 * a live cross-firm query against it is not possible at all — the only
 * way to build a cross-firm rollup is to iterate firms explicitly,
 * accumulating sanitized counts in PHP, then write ONE upsert into the
 * target no-RLS table outside any tenant context afterward (the target
 * table itself needs none — see the create migration's own "WHY THIS
 * TABLE HAS NO RLS AND NO FORCE RLS" docblock).
 */
final class IntegrationPlatformProviderHealthSummaryService
{
    public function __construct(
        private readonly HealthStateService $healthState,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function refreshForProvider(IntegrationProvider $provider): void
    {
        $aggregate = $this->computeForProvider($provider);

        $this->writeSummaryRow($provider, $aggregate);
    }

    /**
     * @return array<string, mixed>
     */
    private function computeForProvider(IntegrationProvider $provider): array
    {
        $connectedFirmCount = 0;
        $disconnectedFirmCount = 0;
        $firmsRequiringAttentionCount = 0;
        $connectedFirmsWithHealthData = 0;
        $credentialOrScopeErrorFirmCount = 0;
        $rateLimitedFirmCount = 0;
        $webhookConfiguredFirmCount = 0;
        $errorCategoryCounts = [];

        // Checkpoint 1 (FirmsVault Live Integrations,
        // checkpoint1-design-health-sandbox.md §A.3.2) accumulators —
        // summed across every connection's new
        // integration_connection_health metrics columns during the
        // same per-firm loop below.
        $totalRequestCount = 0;
        $totalSuccessCount = 0;
        $throttledConnectionCount = 0;
        $tokenRefreshFailureCount = 0;
        $deadLetterCount = 0;
        $latencySum = 0;
        $latencySampleCount = 0;

        Firm::query()
            ->where('activation_status', FirmActivationStatus::Activated)
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $firmId) use (
                $provider,
                &$connectedFirmCount,
                &$disconnectedFirmCount,
                &$firmsRequiringAttentionCount,
                &$connectedFirmsWithHealthData,
                &$credentialOrScopeErrorFirmCount,
                &$rateLimitedFirmCount,
                &$webhookConfiguredFirmCount,
                &$errorCategoryCounts,
                &$totalRequestCount,
                &$totalSuccessCount,
                &$throttledConnectionCount,
                &$tokenRefreshFailureCount,
                &$deadLetterCount,
                &$latencySum,
                &$latencySampleCount,
            ): void {
                $this->tenantContext->runWithFirmContext($firmId, function () use (
                    $provider,
                    $firmId,
                    &$connectedFirmCount,
                    &$disconnectedFirmCount,
                    &$firmsRequiringAttentionCount,
                    &$connectedFirmsWithHealthData,
                    &$credentialOrScopeErrorFirmCount,
                    &$rateLimitedFirmCount,
                    &$webhookConfiguredFirmCount,
                    &$errorCategoryCounts,
                    &$totalRequestCount,
                    &$totalSuccessCount,
                    &$throttledConnectionCount,
                    &$tokenRefreshFailureCount,
                    &$deadLetterCount,
                    &$latencySum,
                    &$latencySampleCount,
                ): void {
                    $connections = FirmIntegration::query()
                        ->where('firm_id', $firmId)
                        ->where('integration_provider_id', $provider->id)
                        ->orderBy('id')
                        ->get(['id', 'status']);

                    if ($connections->isEmpty()) {
                        return;
                    }

                    $connectionIds = $connections->pluck('id');

                    // Checkpoint 1: one batched dead-letter count per
                    // firm (never per connection) — mirrors
                    // App\Services\PlatformConnectionDirectoryService's
                    // own "batched per firm, never per connection"
                    // discipline.
                    $deadLetterCount += DB::table('integration_outbox_events')
                        ->where('firm_id', $firmId)
                        ->whereIn('firm_integration_id', $connectionIds)
                        ->where('status', 'dead_lettered')
                        ->count();

                    foreach ($connections as $connection) {
                        if ($connection->status === ConnectionStatus::Active) {
                            $connectedFirmCount++;
                        } elseif ($connection->status === ConnectionStatus::Disconnected) {
                            $disconnectedFirmCount++;
                        }

                        if (in_array($connection->status, [
                            ConnectionStatus::Error,
                            ConnectionStatus::ReauthorizationRequired,
                            ConnectionStatus::ScopeInsufficient,
                        ], true)) {
                            $firmsRequiringAttentionCount++;
                        }

                        $health = $this->healthState->summaryFor($connection);

                        if (in_array($health->summaryState, [
                            HealthSummaryState::ActionRequired,
                            HealthSummaryState::Unavailable,
                        ], true)) {
                            $firmsRequiringAttentionCount++;
                        }

                        $healthRow = DB::table('integration_connection_health')
                            ->where('firm_integration_id', $connection->id)
                            ->first([
                                'last_failure_category',
                                'last_operation_label',
                                'last_latency_ms',
                                'total_request_count',
                                'total_success_count',
                            ]);

                        $lastFailureCategory = $healthRow?->last_failure_category;

                        if ($lastFailureCategory !== null) {
                            $connectedFirmsWithHealthData++;
                            $errorCategoryCounts[$lastFailureCategory] = ($errorCategoryCounts[$lastFailureCategory] ?? 0) + 1;

                            if (in_array($lastFailureCategory, [
                                SanitizedHealthDiagnostic::CATEGORY_CREDENTIAL_ERROR,
                                SanitizedHealthDiagnostic::CATEGORY_SCOPE_ERROR,
                            ], true)) {
                                $credentialOrScopeErrorFirmCount++;
                            }

                            if ($lastFailureCategory === SanitizedHealthDiagnostic::CATEGORY_RATE_LIMITED) {
                                $rateLimitedFirmCount++;
                                $throttledConnectionCount++;
                            }
                        } else {
                            $connectedFirmsWithHealthData++;
                        }

                        if ($healthRow?->last_operation_label === SanitizedHealthDiagnostic::OPERATION_TOKEN_REFRESH) {
                            $tokenRefreshFailureCount++;
                        }

                        $totalRequestCount += (int) ($healthRow->total_request_count ?? 0);
                        $totalSuccessCount += (int) ($healthRow->total_success_count ?? 0);

                        if ($healthRow?->last_latency_ms !== null) {
                            $latencySum += (int) $healthRow->last_latency_ms;
                            $latencySampleCount++;
                        }

                        $webhookConfigured = DB::table('integration_webhook_routing_index')
                            ->where('firm_integration_id', $connection->id)
                            ->exists();

                        if ($webhookConfigured) {
                            $webhookConfiguredFirmCount++;
                        }
                    }
                });
            });

        // Checkpoint 1 (checkpoint1-design-health-sandbox.md §A.3.3):
        // webhook verification failures CANNOT come from the per-firm
        // connection loop above at all — a rejected inbound webhook
        // frequently cannot be attributed to any resolved connection.
        // Summed instead from the new, platform-owned, no-RLS
        // integration_webhook_verification_failures counter table, a
        // single cheap aggregate query outside any tenant context,
        // windowed to the last 24h (matching
        // recent_error_classification_summary's own "recent" framing).
        $webhookVerificationFailureCount = DB::table('integration_webhook_verification_failures')
            ->where('provider_code', $provider->code)
            ->where('occurred_at', '>=', now()->subDay())
            ->count();

        return [
            'connected_firm_count' => $connectedFirmCount,
            'disconnected_firm_count' => $disconnectedFirmCount,
            'firms_requiring_attention_count' => $firmsRequiringAttentionCount,
            'oauth_health_signal' => $this->deriveOauthHealthSignal($connectedFirmsWithHealthData, $credentialOrScopeErrorFirmCount),
            'webhook_health_signal' => $this->deriveWebhookHealthSignal($connectedFirmCount, $webhookConfiguredFirmCount),
            'rate_limit_condition_signal' => $this->deriveRateLimitSignal($connectedFirmsWithHealthData, $rateLimitedFirmCount),
            'recent_error_classification_summary' => empty($errorCategoryCounts) ? null : $errorCategoryCounts,
            'provider_enabled' => $provider->status === 'active',
            'total_request_count' => $totalRequestCount,
            'total_success_count' => $totalSuccessCount,
            'throttled_connection_count' => $throttledConnectionCount,
            'token_refresh_failure_count' => $tokenRefreshFailureCount,
            'webhook_verification_failure_count' => $webhookVerificationFailureCount,
            'dead_letter_count' => $deadLetterCount,
            'avg_latency_ms' => $latencySampleCount > 0 ? (int) round($latencySum / $latencySampleCount) : null,
        ];
    }

    /**
     * A firm-count-of-zero-with-health-data yields a null signal —
     * never a fabricated "healthy" default — mirroring
     * IntegrationPlatformOverviewSummaryService::mostSevereHealthState()'s
     * identical "no data yet" -> null discipline.
     */
    private function deriveOauthHealthSignal(int $firmsWithHealthData, int $credentialOrScopeErrorFirmCount): ?string
    {
        if ($firmsWithHealthData === 0) {
            return null;
        }

        return $credentialOrScopeErrorFirmCount > 0
            ? HealthSummaryState::ActionRequired->value
            : HealthSummaryState::Healthy->value;
    }

    private function deriveWebhookHealthSignal(int $connectedFirmCount, int $webhookConfiguredFirmCount): ?string
    {
        if ($connectedFirmCount === 0) {
            return null;
        }

        if ($webhookConfiguredFirmCount === 0) {
            return HealthSummaryState::Degraded->value;
        }

        return HealthSummaryState::Healthy->value;
    }

    private function deriveRateLimitSignal(int $firmsWithHealthData, int $rateLimitedFirmCount): ?string
    {
        if ($firmsWithHealthData === 0) {
            return null;
        }

        return $rateLimitedFirmCount > 0
            ? HealthSummaryState::Degraded->value
            : HealthSummaryState::Healthy->value;
    }

    /**
     * @param  array<string, mixed>  $aggregate
     */
    private function writeSummaryRow(IntegrationProvider $provider, array $aggregate): void
    {
        $now = now();

        DB::table('integration_platform_provider_health_summaries')->upsert(
            [array_merge($aggregate, [
                'integration_provider_id' => $provider->id,
                'provider_code' => $provider->code,
                'recent_error_classification_summary' => $aggregate['recent_error_classification_summary'] === null
                    ? null
                    : json_encode($aggregate['recent_error_classification_summary']),
                'computed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])],
            uniqueBy: ['integration_provider_id'],
            update: [
                'provider_code',
                'provider_enabled',
                'connected_firm_count',
                'disconnected_firm_count',
                'firms_requiring_attention_count',
                'oauth_health_signal',
                'webhook_health_signal',
                'rate_limit_condition_signal',
                'recent_error_classification_summary',
                'computed_at',
                'updated_at',
                // Checkpoint 1 (FirmsVault Live Integrations,
                // checkpoint1-design-health-sandbox.md §A.3.2) additions
                // — this array is explicit, so a new field added to
                // $aggregate without also being added here would
                // silently never upsert (confirmed by reading this
                // method directly, per that design doc's own A.4 note).
                'total_request_count',
                'total_success_count',
                'throttled_connection_count',
                'token_refresh_failure_count',
                'webhook_verification_failure_count',
                'dead_letter_count',
                'avg_latency_ms',
            ],
        );
    }
}
