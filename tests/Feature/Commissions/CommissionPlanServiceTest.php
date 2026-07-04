<?php

namespace Tests\Feature\Commissions;

use App\Enums\CommissionPlanStatus;
use App\Services\CommissionPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionPlanServiceTest extends TestCase
{
    use RefreshDatabase;

    private CommissionPlanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CommissionPlanService();
    }

    public function test_create_writes_a_draft_commission_plan(): void
    {
        $plan = $this->service->create([
            'name' => 'Standard rep plan',
            'rate_type' => 'percentage',
            'rate_value' => 12.5,
        ]);

        $this->assertSame(CommissionPlanStatus::Draft, $plan->status);
        $this->assertEquals(12.5, $plan->rate_value);
        $this->assertSame(30, $plan->holding_period_days);
    }

    public function test_activate_and_archive(): void
    {
        $plan = $this->service->create(['name' => 'Plan', 'rate_type' => 'flat', 'rate_value' => 500]);

        $active = $this->service->activate($plan);
        $this->assertSame(CommissionPlanStatus::Active, $active->status);

        $archived = $this->service->archive($active);
        $this->assertSame(CommissionPlanStatus::Archived, $archived->status);
    }
}
