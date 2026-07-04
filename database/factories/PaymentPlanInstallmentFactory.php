<?php

namespace Database\Factories;

use App\Enums\PaymentPlanInstallmentStatus;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentPlanInstallment>
 */
class PaymentPlanInstallmentFactory extends Factory
{
    protected $model = PaymentPlanInstallment::class;

    public function definition(): array
    {
        return [
            'payment_plan_id' => PaymentPlan::factory(),
            'sequence' => 1,
            'amount_cents' => 10000,
            'due_at' => now()->addMonth(),
            'status' => PaymentPlanInstallmentStatus::Scheduled,
            'paid_amount_cents' => 0,
            'paid_at' => null,
            'dunning_state' => null,
        ];
    }

    public function forPlan(PaymentPlan $plan): static
    {
        return $this->state(fn () => ['payment_plan_id' => $plan->id]);
    }

    public function status(PaymentPlanInstallmentStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function dueAt(\DateTimeInterface $dueAt): static
    {
        return $this->state(fn () => ['due_at' => $dueAt]);
    }
}
