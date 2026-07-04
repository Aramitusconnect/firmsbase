<?php

namespace Database\Factories;

use App\Enums\CommissionEventStatus;
use App\Enums\CommissionEventType;
use App\Models\BillingAccount;
use App\Models\CommissionEvent;
use App\Models\CommissionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionEvent>
 */
class CommissionEventFactory extends Factory
{
    protected $model = CommissionEvent::class;

    public function definition(): array
    {
        return [
            'commission_plan_id' => CommissionPlan::factory(),
            'billing_account_id' => BillingAccount::factory(),
            'event_type' => CommissionEventType::NewBusiness->value,
            'status' => CommissionEventStatus::Pending->value,
            'amount_cents' => $this->faker->numberBetween(1000, 50000),
        ];
    }

    public function forBillingAccount(BillingAccount $billingAccount): static
    {
        return $this->state(fn () => ['billing_account_id' => $billingAccount->id]);
    }

    public function attributedTo(\Illuminate\Database\Eloquent\Model $attributable): static
    {
        return $this->state(fn () => [
            'attributable_type' => $attributable::class,
            'attributable_id' => $attributable->id,
        ]);
    }
}
