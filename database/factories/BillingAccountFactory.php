<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\BillingAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingAccount>
 */
class BillingAccountFactory extends Factory
{
    protected $model = BillingAccount::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company().' Billing',
            'status' => RecordStatus::Active,
            'billing_email' => $this->faker->companyEmail(),
        ];
    }
}
