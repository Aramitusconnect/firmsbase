<?php

namespace Database\Factories;

use App\Enums\PlatformLeadStatus;
use App\Models\PlatformLead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformLead>
 */
class PlatformLeadFactory extends Factory
{
    protected $model = PlatformLead::class;

    public function definition(): array
    {
        return [
            'company_name' => $this->faker->company(),
            'contact_name' => $this->faker->name(),
            'contact_email' => $this->faker->safeEmail(),
            'contact_phone' => $this->faker->phoneNumber(),
            'source' => $this->faker->randomElement(['website', 'referral', 'conference', 'outbound']),
            'status' => PlatformLeadStatus::New->value,
        ];
    }

    public function status(PlatformLeadStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }
}
