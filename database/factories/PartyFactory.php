<?php

namespace Database\Factories;

use App\Enums\PartyEntityType;
use App\Models\Firm;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Party>
 */
class PartyFactory extends Factory
{
    protected $model = Party::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'name' => $this->faker->name(),
            'entity_type' => PartyEntityType::Individual,
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'company' => null,
            'normalized_search_keys' => null,
            'notes' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function company(): static
    {
        return $this->state(fn () => [
            'entity_type' => PartyEntityType::Company,
            'name' => $this->faker->company(),
        ]);
    }
}
