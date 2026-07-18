<?php

namespace Tests\Feature\CustomerSuccess;

use App\Enums\CustomerHealthRiskLevel;
use App\Models\CustomerSuccessHealthScore;
use App\Models\Firm;
use App\Services\CustomerSuccessHealthScoreService;
use App\Services\QueueHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerSuccessHealthScoreServiceTest extends TestCase
{
    use RefreshDatabase;

    private CustomerSuccessHealthScoreService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CustomerSuccessHealthScoreService(new QueueHealthService());
    }

    public function test_compute_creates_a_new_snapshot_row(): void
    {
        $firm = Firm::factory()->create();

        $score = $this->service->compute($firm);

        // customer_success_health_scores is FORCE-RLS protected (Section
        // 39A-5, Checkpoint 1) — assertDatabaseHas() runs an unscoped
        // query with no tenant context and would always see zero rows
        // regardless of whether compute() actually persisted anything,
        // so persistence is verified under the row's own firm context
        // instead, the same way every other force-activated table's
        // tests do.
        $found = $this->runWithFirmContext(
            $firm,
            fn () => CustomerSuccessHealthScore::withoutGlobalScopes()->where('firm_id', $firm->id)->exists(),
        );

        $this->assertTrue($found, 'compute() must persist a row visible under its own firm context.');
        $this->assertNotNull($score->computed_at);
    }

    public function test_no_active_users_computes_a_risk_flag_and_lower_score(): void
    {
        $firm = Firm::factory()->create();

        $score = $this->service->compute($firm);

        $this->assertContains('no_active_users', $score->risk_flags);
        $this->assertLessThan(100, $score->score);
        $this->assertContains($score->risk_level, [CustomerHealthRiskLevel::AtRisk, CustomerHealthRiskLevel::Critical, CustomerHealthRiskLevel::Healthy]);
    }

    public function test_computing_twice_creates_two_distinct_snapshot_rows(): void
    {
        $firm = Firm::factory()->create();

        $this->service->compute($firm);
        $this->service->compute($firm);

        // Same FORCE-RLS reasoning as test_compute_creates_a_new_snapshot_row
        // above — count under the firm's own context, not an unscoped query.
        $count = $this->runWithFirmContext(
            $firm,
            fn () => CustomerSuccessHealthScore::withoutGlobalScopes()->where('firm_id', $firm->id)->count(),
        );

        $this->assertSame(2, $count);
    }
}
