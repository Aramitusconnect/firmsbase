<?php

namespace Database\Factories;

use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterParty>
 */
class MatterPartyFactory extends Factory
{
    protected $model = MatterParty::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'party_id' => Party::factory(),
            'relationship_type' => null,
            'is_opposing' => false,
            'is_related' => false,
        ];
    }

    public function forMatter(Matter $matter): static
    {
        return $this->state(fn () => ['matter_id' => $matter->id]);
    }

    public function forParty(Party $party): static
    {
        return $this->state(fn () => ['party_id' => $party->id]);
    }

    public function opposing(): static
    {
        return $this->state(fn () => ['is_opposing' => true]);
    }
}
