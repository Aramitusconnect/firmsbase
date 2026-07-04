<?php

namespace Database\Factories;

use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
use App\Models\PlatformLead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Opportunity>
 */
class OpportunityFactory extends Factory
{
    protected $model = Opportunity::class;

    public function definition(): array
    {
        return [
            'platform_lead_id' => PlatformLead::factory(),
            'status' => OpportunityStatus::Open->value,
            'estimated_seats' => $this->faker->numberBetween(1, 50),
            'estimated_mrr_cents' => $this->faker->numberBetween(10000, 500000),
        ];
    }

    public function forLead(PlatformLead $lead): static
    {
        return $this->state(fn () => ['platform_lead_id' => $lead->id]);
    }

    public function status(OpportunityStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }
}
