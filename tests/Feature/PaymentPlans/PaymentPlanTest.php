<?php

namespace Tests\Feature\PaymentPlans;

use App\Enums\PaymentPlanStatus;
use App\Models\PaymentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $plan = PaymentPlan::factory()->create();

        $this->assertDatabaseHas('payment_plans', ['id' => $plan->id]);
        $this->assertSame(PaymentPlanStatus::Draft, $plan->status);
        $this->assertTrue($plan->isEditable());
    }

    public function test_active_plan_is_not_editable(): void
    {
        $plan = PaymentPlan::factory()->active()->create();

        $this->assertFalse($plan->isEditable());
        $this->assertTrue($plan->isDunningEligibleStatus());
    }
}
