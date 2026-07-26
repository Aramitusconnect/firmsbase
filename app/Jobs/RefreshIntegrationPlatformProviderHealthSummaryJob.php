<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\Models\IntegrationProvider;
use App\Services\IntegrationPlatformProviderHealthSummaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RefreshIntegrationPlatformProviderHealthSummaryJob — Phase 2 of the
 * FirmsVault Platform Admin Control Center mission ("Integration
 * Operations Center"). The per-provider, per-tick unit of queued work
 * that refreshes one provider's
 * `integration_platform_provider_health_summaries` row, mirroring
 * App\Jobs\RefreshIntegrationPlatformOverviewSummaryJob's exact
 * two-layer shape:
 * App\Console\Commands\RefreshIntegrationPlatformProviderHealthSummariesCommand
 * (Layer 1, a plain, non-tenant scheduled command) enumerates every
 * registered provider and dispatches one instance of this job per
 * provider id.
 *
 * Constructor carries a scalar provider id only — never an
 * IntegrationProvider model, never anything pre-fetched at dispatch
 * time — the real row is resolved fresh inside handle().
 */
final class RefreshIntegrationPlatformProviderHealthSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $providerId,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('platform-provider-health-refresh:'.$this->providerId))->releaseAfter(60)->expireAfter(900),
        ];
    }

    public function handle(IntegrationPlatformProviderHealthSummaryService $summaryService): void
    {
        $provider = IntegrationProvider::query()->find($this->providerId);

        if ($provider === null) {
            // The provider was deleted between enumeration and this job
            // running — nothing to refresh; the summary row itself
            // cascade-deletes with its parent provider regardless.
            return;
        }

        $summaryService->refreshForProvider($provider);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('RefreshIntegrationPlatformProviderHealthSummaryJob: failed to refresh the provider health summary.', [
            'integration_provider_id' => $this->providerId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
