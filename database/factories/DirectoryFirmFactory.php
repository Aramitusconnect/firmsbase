<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\DirectoryFirm;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DirectoryFirm>
 */
class DirectoryFirmFactory extends Factory
{
    protected $model = DirectoryFirm::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->company().' Law';

        return [
            'firm_id' => null,
            'slug' => DirectoryFirm::generateUniqueSlug($name),
            'legal_name' => $name.' PLLC',
            'display_name' => $name,
            'name_normalized' => Str::lower($name),
            'phone' => $this->faker->numerify('##########'),
            'website' => 'https://'.Str::slug($name).'.example.com',
            'public_email' => null,
            'founding_year' => $this->faker->numberBetween(1970, 2020),
            'description' => $this->faker->paragraph(),
            'consultation_modes' => ['in_person', 'phone'],
            'accepting_inquiries' => true,
            'is_claimed' => false,
            'claimed_at' => null,
            'is_marketplace_member' => false,
            'membership_activated_at' => null,
            'publication_state' => DirectoryPublicationState::Published,
            'source_type' => DataProvenanceSourceType::AdminEntered,
            'source_reference' => null,
            'imported_at' => now(),
            'last_verified_at' => null,
            'last_confirmed_by_firm_at' => null,
            'completeness_score' => 40,
        ];
    }

    public function unclaimed(): static
    {
        return $this->state(fn () => ['is_claimed' => false, 'claimed_at' => null, 'is_marketplace_member' => false]);
    }

    public function claimed(): static
    {
        return $this->state(fn () => ['is_claimed' => true, 'claimed_at' => now(), 'is_marketplace_member' => false]);
    }

    public function member(): static
    {
        return $this->state(fn () => [
            'is_claimed' => true,
            'claimed_at' => now(),
            'is_marketplace_member' => true,
            'membership_activated_at' => now(),
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['publication_state' => DirectoryPublicationState::Draft]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['publication_state' => DirectoryPublicationState::Suspended]);
    }
}
