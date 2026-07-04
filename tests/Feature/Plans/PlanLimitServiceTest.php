<?php

namespace Tests\Feature\Plans;

use App\Enums\PlanLimitMetric;
use App\Models\Plan;
use App\Services\PlanLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanLimitServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlanLimitService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PlanLimitService();
    }

    public function test_set_limit_creates_a_row(): void
    {
        $plan = Plan::factory()->create();

        $limit = $this->service->setLimit($plan, PlanLimitMetric::SeatsAttorney, 5);

        $this->assertSame(5, $limit->limit_value);
    }

    public function test_set_limit_is_idempotent_per_plan_and_metric(): void
    {
        $plan = Plan::factory()->create();

        $first = $this->service->setLimit($plan, PlanLimitMetric::StorageGb, 100);
        $second = $this->service->setLimit($plan, PlanLimitMetric::StorageGb, 250);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(250, $second->fresh()->limit_value);
    }

    public function test_null_limit_value_means_unlimited(): void
    {
        $plan = Plan::factory()->create();

        $limit = $this->service->setLimit($plan, PlanLimitMetric::ApiCallsMonthly, null);

        $this->assertTrue($limit->isUnlimited());
    }

    public function test_limit_value_returns_null_when_no_row_exists(): void
    {
        $plan = Plan::factory()->create();

        $this->assertNull($this->service->limitValue($plan, PlanLimitMetric::AiTokensMonthly));
    }
}
