<?php

namespace Database\Factories;

use App\Enums\PaymentPlanStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\PaymentPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentPlan>
 */
class PaymentPlanFactory extends Factory
{
    protected $model = PaymentPlan::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'client_id' => Client::factory(),
            'matter_id' => null,
            'invoice_id' => null,
            'status' => PaymentPlanStatus::Draft,
            'total_cents' => 0,
            'currency' => 'usd',
            'installment_count' => 0,
            'supersedes_payment_plan_id' => null,
            'created_by' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'client_id' => Client::factory()->forFirm($firm),
        ]);
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn () => [
            'firm_id' => $client->firm_id,
            'client_id' => $client->id,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => PaymentPlanStatus::Active, 'activated_at' => now()]);
    }
}
