<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Analytics;

use App\Marketplace\Models\MarketplaceAnalyticsEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PruneMarketplaceAnalyticsEventsCommandTest — Mission 2 (MyAttorney
 * Marketplace Core), checkpoint 13.
 */
class PruneMarketplaceAnalyticsEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_rows_older_than_the_retention_window_and_keeps_recent_ones(): void
    {
        config(['marketplace.analytics_retention_days' => 30]);

        $stale = MarketplaceAnalyticsEvent::factory()->firmProfileViewed()->create(['occurred_at' => now()->subDays(40)]);
        $recent = MarketplaceAnalyticsEvent::factory()->firmProfileViewed()->create(['occurred_at' => now()->subDays(5)]);

        $this->artisan('marketplace:analytics:prune')->assertSuccessful();

        $this->assertModelMissing($stale);
        $this->assertModelExists($recent);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        config(['marketplace.analytics_retention_days' => 30]);

        $stale = MarketplaceAnalyticsEvent::factory()->firmProfileViewed()->create(['occurred_at' => now()->subDays(40)]);

        $this->artisan('marketplace:analytics:prune', ['--dry-run' => true])->assertSuccessful();

        $this->assertModelExists($stale);
    }
}
