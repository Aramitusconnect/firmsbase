<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Analytics;

use App\Enums\AiProvider;
use App\Marketplace\Models\MarketplaceAiUsageEvent;
use App\Marketplace\Services\MarketplaceAiUsageReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * MarketplaceAiUsageReportingServiceTest — SuperAdmin console
 * professionalization mission (MYAT8). Every marketplace_ai_usage_events
 * row created by this test's factory defaults to firm_id = null (the
 * only shape a context-free SuperAdmin session can ever legitimately
 * see per that table's own RLS policy — see the service's own
 * docblock), so no explicit tenant-context juggling is needed here.
 */
class MarketplaceAiUsageReportingServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketplaceAiUsageReportingService $reporting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reporting = app(MarketplaceAiUsageReportingService::class);
    }

    public function test_calls_since_counts_only_events_inside_the_window(): void
    {
        MarketplaceAiUsageEvent::factory()->create(['created_at' => now()]);
        MarketplaceAiUsageEvent::factory()->create(['created_at' => now()->subDays(40)]);

        $this->assertSame(1, $this->reporting->callsSince(Carbon::now()->subDays(30)));
    }

    public function test_tokens_since_sums_in_and_out_separately(): void
    {
        MarketplaceAiUsageEvent::factory()->create(['tokens_in' => 100, 'tokens_out' => 40, 'created_at' => now()]);
        MarketplaceAiUsageEvent::factory()->create(['tokens_in' => 20, 'tokens_out' => 10, 'created_at' => now()]);

        $tokens = $this->reporting->tokensSince(Carbon::now()->subDays(7));

        $this->assertSame(120, $tokens['in']);
        $this->assertSame(50, $tokens['out']);
    }

    public function test_tokens_since_returns_zero_with_no_events(): void
    {
        $tokens = $this->reporting->tokensSince(Carbon::now()->subDays(7));

        $this->assertSame(0, $tokens['in']);
        $this->assertSame(0, $tokens['out']);
    }

    public function test_by_provider_since_groups_and_counts_correctly(): void
    {
        MarketplaceAiUsageEvent::factory()->count(2)->create(['provider' => AiProvider::OpenAi, 'created_at' => now()]);
        MarketplaceAiUsageEvent::factory()->create(['provider' => AiProvider::Anthropic, 'created_at' => now()]);

        $rows = $this->reporting->byProviderSince(Carbon::now()->subDays(7));

        $openAi = $rows->firstWhere('provider', 'openai');
        $anthropic = $rows->firstWhere('provider', 'anthropic');

        $this->assertSame(2, $openAi['calls']);
        $this->assertSame(1, $anthropic['calls']);
    }

    public function test_by_model_since_groups_and_counts_correctly(): void
    {
        MarketplaceAiUsageEvent::factory()->count(3)->create(['model' => 'model-a', 'created_at' => now()]);
        MarketplaceAiUsageEvent::factory()->create(['model' => 'model-b', 'created_at' => now()]);

        $rows = $this->reporting->byModelSince(Carbon::now()->subDays(7));

        $this->assertSame('model-a', $rows->first()['model']);
        $this->assertSame(3, $rows->first()['calls']);
    }
}
