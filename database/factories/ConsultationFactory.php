<?php

namespace Database\Factories;

use App\Models\Consultation;
use App\Models\Firm;
use App\Models\FirmLead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consultation>
 */
class ConsultationFactory extends Factory
{
    protected $model = Consultation::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'firm_lead_id' => FirmLead::factory(),
            'consultation_outcome_id' => null,
            'scheduled_at' => now()->addDay(),
            'held_at' => null,
            'notes' => $this->faker->sentence(),
            'converted' => false,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function forLead(FirmLead $lead): static
    {
        return $this->state(fn () => ['firm_id' => $lead->firm_id, 'firm_lead_id' => $lead->id]);
    }

    public function held(): static
    {
        return $this->state(fn () => ['held_at' => now()]);
    }
}
