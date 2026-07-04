<?php

namespace Database\Factories;

use App\Models\EmployeeRate;
use App\Models\Firm;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeRate>
 */
class EmployeeRateFactory extends Factory
{
    protected $model = EmployeeRate::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'user_id' => User::factory(),
            'billing_rate_cents' => 25000,
            'cost_rate_cents' => 12000,
            'currency' => 'usd',
            'effective_from' => now()->subMonth(),
            'effective_to' => null,
            'created_by' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    public function closed(\DateTimeInterface $effectiveTo): static
    {
        return $this->state(fn () => ['effective_to' => $effectiveTo]);
    }
}
