<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Budget;

use App\Enums\AiMode;
use App\Marketplace\Models\MarketplaceAiUsageEvent;
use App\Models\AiUsageEvent;
use App\Models\Firm;
use App\Services\AiBudgetEnforcementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Ai\Concerns\SetsUpAiEntitledFirm;
use Tests\TestCase;

/**
 * A firm's AI token budget is ONE ceiling over ALL of its AI spend.
 *
 * Spend is recorded in two tables that stay deliberately separate:
 * ai_usage_events (authenticated firm users, user_id required) and
 * marketplace_ai_usage_events (anonymous prospects, no user record exists).
 * Reading only one of them lets a firm spend its budget twice, so these tests
 * pin the union — and pin the scoping that makes the union safe, because a sum
 * across two tables is only as trustworthy as its firm_id filters.
 */
class AiCombinedBudgetEnforcementTest extends TestCase
{
    use RefreshDatabase, SetsUpAiEntitledFirm;

    private function budget(): AiBudgetEnforcementService
    {
        return app(AiBudgetEnforcementService::class);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function period(): array
    {
        return [
            new \DateTimeImmutable('-1 day'),
            new \DateTimeImmutable('+1 day'),
        ];
    }

    private function firmWithTokenLimit(int $limit): Firm
    {
        $firm = $this->makeAiEntitledFirm(AiMode::FirmOwned);

        $this->runWithFirmContext($firm, fn () => $firm->aiSettings->update([
            'token_limit_per_period' => $limit,
        ]));

        return $firm->fresh(['aiSettings']);
    }

    private function recordGlobalUsage(Firm $firm, int $tokens): void
    {
        $this->runWithFirmContext($firm, fn () => AiUsageEvent::factory()->forFirm($firm)->create([
            'tokens_in' => $tokens,
            'tokens_out' => 0,
            'cost_cents' => 0,
        ]));
    }

    private function recordMarketplaceUsage(?Firm $firm, int $tokens): void
    {
        MarketplaceAiUsageEvent::factory()->create([
            'firm_id' => $firm?->id,
            'tokens_in' => $tokens,
            'tokens_out' => 0,
        ]);
    }

    private function allowed(Firm $firm, int $additionalTokens = 0): bool
    {
        [$start, $end] = $this->period();

        return $this->runWithFirmContext(
            $firm,
            fn () => $this->budget()->checkFirmBudget($firm, $additionalTokens, 0, $start, $end)->allowed(),
        );
    }

    public function test_neither_source_alone_exceeds_the_budget_but_together_they_do(): void
    {
        // Stated as three checks in one test on purpose: the point is not that
        // 1100 > 1000, it is that BOTH halves individually fit. A service
        // reading only one table passes the first two and still says "allowed".
        $firm = $this->firmWithTokenLimit(1000);

        $this->recordGlobalUsage($firm, 600);
        $this->assertTrue($this->allowed($firm), '600 global tokens alone is inside a 1000-token budget.');

        $other = $this->firmWithTokenLimit(1000);
        $this->recordMarketplaceUsage($other, 500);
        $this->assertTrue($this->allowed($other), '500 marketplace tokens alone is inside a 1000-token budget.');

        $this->recordMarketplaceUsage($firm, 500);
        $this->assertFalse($this->allowed($firm), '600 global + 500 marketplace = 1100 must exceed a 1000-token budget.');
    }

    public function test_the_combined_total_is_a_sum_and_not_a_maximum(): void
    {
        $firm = $this->firmWithTokenLimit(1000);

        $this->recordGlobalUsage($firm, 900);
        $this->recordMarketplaceUsage($firm, 900);

        // max() would read 900 and allow; sum reads 1800 and denies.
        $this->assertFalse($this->allowed($firm));
    }

    public function test_another_firms_marketplace_usage_never_counts_against_this_firm(): void
    {
        $firmA = $this->firmWithTokenLimit(1000);
        $firmB = $this->firmWithTokenLimit(1000);

        $this->recordGlobalUsage($firmA, 600);
        $this->recordMarketplaceUsage($firmB, 5000);

        $this->assertTrue($this->allowed($firmA), "Firm B's marketplace spend must not consume Firm A's budget.");
        $this->assertFalse($this->allowed($firmB), "Firm B's own 5000 tokens must still exceed Firm B's budget.");
    }

    public function test_marketplace_usage_with_no_firm_counts_against_nobody(): void
    {
        // A prospect can reach the marketplace assistant before any firm is
        // resolved, leaving firm_id null. Those rows are real spend, but they
        // belong to no firm and must never be charged to one.
        $firm = $this->firmWithTokenLimit(1000);

        $this->recordGlobalUsage($firm, 600);
        $this->recordMarketplaceUsage(null, 5000);

        $this->assertTrue($this->allowed($firm));
    }

    public function test_a_conservative_pending_allowance_is_what_stops_a_near_limit_firm(): void
    {
        // 990/1000 is "not yet over" — a zero-increment probe allows the call
        // and the firm finishes far past its ceiling. The allowance is the
        // whole mechanism, so both sides of the boundary are pinned.
        $firm = $this->firmWithTokenLimit(1000);
        $this->recordGlobalUsage($firm, 990);

        $this->assertTrue($this->allowed($firm, 0), 'Guard assertion: with no allowance the firm is not yet over.');
        $this->assertTrue($this->allowed($firm, 10), '990 + 10 lands exactly on the limit and is permitted.');
        $this->assertFalse($this->allowed($firm, 11), '990 + 11 would finish over the limit and must be refused BEFORE the call.');
    }

    public function test_the_near_limit_allowance_also_counts_marketplace_usage(): void
    {
        $firm = $this->firmWithTokenLimit(1000);

        $this->recordGlobalUsage($firm, 490);
        $this->recordMarketplaceUsage($firm, 500);

        $this->assertFalse($this->allowed($firm, 11));
    }

    public function test_ai_usage_events_still_requires_a_user_id(): void
    {
        // The reason the two tables were not merged. If this column ever became
        // nullable, "merge the tables" would look reasonable and the audit
        // guarantee that every authenticated AI action names its actor would be
        // quietly gone.
        $column = Schema::getColumns('ai_usage_events');
        $userId = collect($column)->firstWhere('name', 'user_id');

        $this->assertNotNull($userId, 'ai_usage_events.user_id must exist.');
        $this->assertFalse($userId['nullable'], 'ai_usage_events.user_id must stay NOT NULL.');
    }
}
