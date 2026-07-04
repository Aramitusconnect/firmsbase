<?php

namespace Database\Factories;

use App\Enums\TrialRequestStatus;
use App\Models\Opportunity;
use App\Models\TrialRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrialRequest>
 */
class TrialRequestFactory extends Factory
{
    protected $model = TrialRequest::class;

    public function definition(): array
    {
        return [
            'opportunity_id' => Opportunity::factory(),
            'status' => TrialRequestStatus::Requested->value,
            'requested_at' => now(),
        ];
    }

    public function forOpportunity(Opportunity $opportunity): static
    {
        return $this->state(fn () => ['opportunity_id' => $opportunity->id]);
    }
}
