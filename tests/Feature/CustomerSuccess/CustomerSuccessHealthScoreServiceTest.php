<?php

namespace Tests\Feature\CustomerSuccess;

use App\Enums\CustomerHealthRiskLevel;
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

        $this->assertDatabaseHas('customer_success_health_scores', ['firm_id' => $firm->id]);
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

        $this->assertSame(2, \App\Models\CustomerSuccessHealthScore::query()->where('firm_id', $firm->id)->count());
    }
}
