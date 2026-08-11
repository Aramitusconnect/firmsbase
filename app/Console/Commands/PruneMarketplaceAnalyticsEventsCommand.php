<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Marketplace\Models\MarketplaceAnalyticsEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * marketplace:analytics:prune — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 13. A plain, non-tenant, non-queued Artisan
 * command deleting directory_marketplace_analytics_events rows older
 * than config('marketplace.analytics_retention_days') — same shape as
 * SweepIntegrationRetentionCommand's own direct-delete path for a
 * platform-owned, no-RLS table: a single bounded operation, not worth
 * a queued job. Scheduled `daily()->withoutOverlapping()` in
 * bootstrap/app.php.
 */
final class PruneMarketplaceAnalyticsEventsCommand extends Command
{
    protected $signature = 'marketplace:analytics:prune {--dry-run}';

    protected $description = 'Deletes directory_marketplace_analytics_events rows past the configured retention window.';

    public function handle(): int
    {
        $cutoff = Carbon::now()->subDays((int) config('marketplace.analytics_retention_days'));
        $query = MarketplaceAnalyticsEvent::query()->where('occurred_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $this->info("Would delete {$query->count()} row(s) older than {$cutoff->toDateString()}.");

            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("Deleted {$deleted} row(s) older than {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
