<?php

namespace Database\Factories;

use App\Models\Firm;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentPlanEvent>
 */
class PaymentPlanEventFactory extends Factory
{
    protected $model = PaymentPlanEvent::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'payment_plan_id' => PaymentPlan::factory(),
            'event_type' => 'created',
            'metadata_json' => [],
            'actor_user_id' => null,
        ];
    }

    public function forPlan(PaymentPlan $plan): static
    {
        return $this->state(fn () => ['firm_id' => $plan->firm_id, 'payment_plan_id' => $plan->id]);
    }

    public function eventType(string $type): static
    {
        return $this->state(fn () => ['event_type' => $type]);
    }
}
