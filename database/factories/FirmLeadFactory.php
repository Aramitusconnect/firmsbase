<?php

namespace Database\Factories;

use App\Enums\FirmLeadStatus;
use App\Models\Firm;
use App\Models\FirmLead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FirmLead>
 */
class FirmLeadFactory extends Factory
{
    protected $model = FirmLead::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'lead_source_id' => null,
            'practice_area_interest_id' => null,
            'name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'status' => FirmLeadStatus::New,
            'assigned_to' => null,
            'converted_client_id' => null,
            'converted_at' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function status(FirmLeadStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
