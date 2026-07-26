<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Integrations\Models\IntegrationProvider;
use App\Jobs\RefreshIntegrationPlatformProviderHealthSummaryJob;
use Illuminate\Console\Command;

/**
 * integrations:platform-provider-health:refresh — Layer 1 of the
 * platform provider health summary refresh loop (Phase 2 of the
 * FirmsVault Platform Admin Control Center mission, "Integration
 * Operations Center"), mirroring
 * App\Console\Commands\RefreshIntegrationPlatformOverviewSummariesCommand's
 * exact shape. A plain, non-tenant, non-ShouldQueue Artisan command —
 * `integration_providers` is not FORCE-RLS'd (it is a small, static,
 * seeded-only global reference catalog — see that table's own create
 * migration), so it is safe to enumerate without any tenant context.
 * Every provider is dispatched, not only currently-active ones, so a
 * disabled provider still gets a summary row reflecting
 * provider_enabled = false rather than a stale/missing one. Scheduled
 * in bootstrap/app.php alongside the existing Checkpoint 11
 * platform-overview refresh entry.
 */
final class RefreshIntegrationPlatformProviderHealthSummariesCommand extends Command
{
    protected $signature = 'integrations:platform-provider-health:refresh';

    protected $description = 'Dispatches one RefreshIntegrationPlatformProviderHealthSummaryJob per registered provider to refresh integration_platform_provider_health_summaries.';

    public function handle(): int
    {
        IntegrationProvider::query()
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $providerId) => RefreshIntegrationPlatformProviderHealthSummaryJob::dispatch($providerId));

        return self::SUCCESS;
    }
}
