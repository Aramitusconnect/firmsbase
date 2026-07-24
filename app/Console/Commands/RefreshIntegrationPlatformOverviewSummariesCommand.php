<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FirmActivationStatus;
use App\Jobs\RefreshIntegrationPlatformOverviewSummaryJob;
use App\Models\Firm;
use Illuminate\Console\Command;

/**
 * integrations:platform-overview:refresh — Layer 1 of the platform
 * overview summary refresh loop (Checkpoint 11, frozen-design-post-
 * security-review.md §5), mirroring
 * App\Console\Commands\DispatchOutboxEventsCommand's exact shape. A
 * plain, non-tenant, non-ShouldQueue Artisan command — `firms` is not
 * FORCE-RLS'd, so it is safe to enumerate without any tenant context.
 * Scheduled in bootstrap/app.php alongside the existing Checkpoint 8
 * outbox/sync-retry/retention entries.
 */
final class RefreshIntegrationPlatformOverviewSummariesCommand extends Command
{
    protected $signature = 'integrations:platform-overview:refresh';

    protected $description = 'Dispatches one RefreshIntegrationPlatformOverviewSummaryJob per activated firm to refresh integration_platform_overview_summaries.';

    public function handle(): int
    {
        Firm::query()
            ->where('activation_status', FirmActivationStatus::Activated)
            ->pluck('id')
            ->each(fn (int $firmId) => RefreshIntegrationPlatformOverviewSummaryJob::dispatch($firmId));

        return self::SUCCESS;
    }
}
