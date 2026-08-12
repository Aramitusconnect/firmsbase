<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Intake;

use App\Marketplace\Models\MarketplaceAiUsageEvent;
use App\Marketplace\Services\MarketplaceAiUsageThrottleService;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 6,
 * requirement #12 — MarketplaceAiUsageThrottleService's per-IP,
 * per-session, and per-session-token ceilings.
 */
class MarketplaceAiUsageThrottleServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): MarketplaceAiUsageThrottleService
    {
        return app(MarketplaceAiUsageThrottleService::class);
    }

    public function test_evaluate_allows_a_fresh_ip_and_session(): void
    {
        $decision = $this->service()->evaluate(null, 'session-a', '203.0.113.20');

        $this->assertTrue($decision->allowed);
    }

    public function test_evaluate_denies_once_the_per_session_request_ceiling_is_reached(): void
    {
        $service = $this->service();

        for ($i = 0; $i < MarketplaceAiUsageThrottleService::MAX_REQUESTS_PER_SESSION_PER_HOUR; $i++) {
            $service->recordAttempt('session-b', "203.0.113.{$i}");
        }

        $decision = $service->evaluate(null, 'session-b', '203.0.113.99');

        $this->assertFalse($decision->allowed);
    }

    public function test_evaluate_denies_once_the_per_ip_request_ceiling_is_reached(): void
    {
        $service = $this->service();

        for ($i = 0; $i < MarketplaceAiUsageThrottleService::MAX_REQUESTS_PER_IP_PER_HOUR; $i++) {
            $service->recordAttempt("session-c-{$i}", '203.0.113.30');
        }

        $decision = $service->evaluate(null, 'session-c-fresh', '203.0.113.30');

        $this->assertFalse($decision->allowed);
    }

    public function test_throttle_ceilings_are_isolated_per_session(): void
    {
        $service = $this->service();

        for ($i = 0; $i < MarketplaceAiUsageThrottleService::MAX_REQUESTS_PER_SESSION_PER_HOUR; $i++) {
            $service->recordAttempt('session-d', "203.0.113.{$i}");
        }

        $this->assertTrue($service->tooManyAttemptsForSession('session-d'));
        $this->assertFalse($service->tooManyAttemptsForSession('session-e'));
    }

    public function test_tokens_used_in_window_is_scoped_independently_per_firm_no_cross_firm_leakage(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        // Firm A's own marketplace_ai_usage_events row for a given
        // session hash must never be visible while resolving Firm B's
        // token usage for that SAME session hash — proving the token
        // ceiling itself carries no cross-firm leak, on top of the RLS
        // layer's own guarantee.
        MarketplaceAiUsageEvent::factory()->create([
            'firm_id' => $firmA->id,
            'session_hash' => 'shared-session',
            'tokens_in' => 500,
            'tokens_out' => 500,
        ]);

        $usedForFirmB = $this->service()->tokensUsedInWindow($firmB, 'shared-session');

        $this->assertSame(0, $usedForFirmB);
    }

    public function test_exceeds_token_ceiling_is_true_once_the_window_sum_reaches_the_ceiling(): void
    {
        $firm = Firm::factory()->create();
        MarketplaceAiUsageEvent::factory()->create([
            'firm_id' => $firm->id,
            'session_hash' => 'token-heavy-session',
            'tokens_in' => MarketplaceAiUsageThrottleService::MAX_TOKENS_PER_SESSION_PER_WINDOW,
            'tokens_out' => 0,
        ]);

        $this->assertTrue($this->service()->exceedsTokenCeiling($firm, 'token-heavy-session'));
    }

    public function test_platform_scoped_token_window_null_firm_is_isolated_from_firm_scoped_rows(): void
    {
        $firm = Firm::factory()->create();
        MarketplaceAiUsageEvent::factory()->create([
            'firm_id' => $firm->id,
            'session_hash' => 'mixed-session',
            'tokens_in' => 999,
            'tokens_out' => 999,
        ]);

        $platformUsage = $this->service()->tokensUsedInWindow(null, 'mixed-session');

        $this->assertSame(0, $platformUsage);
    }
}
