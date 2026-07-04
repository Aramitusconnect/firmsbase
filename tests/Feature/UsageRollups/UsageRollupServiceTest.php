<?php

namespace Tests\Feature\UsageRollups;

use App\Enums\UsageRollupMetric;
use App\Models\BillingAccount;
use App\Models\Firm;
use App\Services\UsageRollupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageRollupServiceTest extends TestCase
{
    use RefreshDatabase;

    private UsageRollupService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UsageRollupService();
    }

    public function test_record_usage_creates_a_row(): void
    {
        $account = BillingAccount::factory()->create();
        $firm = Firm::factory()->create(['billing_account_id' => $account->id]);
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        $rollup = $this->service->recordUsage($account, $firm, UsageRollupMetric::AiTokens, 5000, $start, $end, 'tokens');

        $this->assertSame($firm->id, $rollup->firm_id);
        $this->assertSame(5000, $rollup->quantity);
        $this->assertFalse($rollup->isAccountLevelAggregate());
    }

    public function test_total_for_metric_sums_per_firm_rows_when_no_account_level_row_exists(): void
    {
        $account = BillingAccount::factory()->create();
        $firmA = Firm::factory()->create(['billing_account_id' => $account->id]);
        $firmB = Firm::factory()->create(['billing_account_id' => $account->id]);
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        $this->service->recordUsage($account, $firmA, UsageRollupMetric::StorageBytes, 1000, $start, $end);
        $this->service->recordUsage($account, $firmB, UsageRollupMetric::StorageBytes, 2000, $start, $end);

        $total = $this->service->totalForMetric($account, UsageRollupMetric::StorageBytes, $start, $end);

        $this->assertSame(3000, $total);
    }

    public function test_total_for_metric_prefers_the_account_level_aggregate_row_when_present(): void
    {
        $account = BillingAccount::factory()->create();
        $firm = Firm::factory()->create(['billing_account_id' => $account->id]);
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        $this->service->recordUsage($account, $firm, UsageRollupMetric::SeatsActive, 3, $start, $end);
        $this->service->recordUsage($account, null, UsageRollupMetric::SeatsActive, 10, $start, $end);

        $total = $this->service->totalForMetric($account, UsageRollupMetric::SeatsActive, $start, $end);

        $this->assertSame(10, $total, 'The pre-aggregated account-level row must be used instead of re-summing per-firm rows.');
    }

    public function test_is_within_budget(): void
    {
        $account = BillingAccount::factory()->create();
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();
        $this->service->recordUsage($account, null, UsageRollupMetric::AiTokens, 800, $start, $end);

        $this->assertTrue($this->service->isWithinBudget($account, UsageRollupMetric::AiTokens, 1000, $start, $end));
        $this->assertFalse($this->service->isWithinBudget($account, UsageRollupMetric::AiTokens, 500, $start, $end));
    }
}
