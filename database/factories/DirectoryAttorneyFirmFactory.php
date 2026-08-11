<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Marketplace\Enums\DirectoryAttorneyFirmRelationshipState;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryAttorneyFirm;
use App\Marketplace\Models\DirectoryFirm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DirectoryAttorneyFirm>
 */
class DirectoryAttorneyFirmFactory extends Factory
{
    protected $model = DirectoryAttorneyFirm::class;

    public function definition(): array
    {
        return [
            'directory_attorney_id' => DirectoryAttorney::factory(),
            'directory_firm_id' => DirectoryFirm::factory(),
            'firm_office_id' => null,
            'relationship_state' => DirectoryAttorneyFirmRelationshipState::Current,
            'title' => 'Partner',
            'is_primary_firm' => true,
            'started_at' => now()->subYears(2),
            'ended_at' => null,
            'source_type' => DataProvenanceSourceType::AdminEntered,
            'source_reference' => null,
        ];
    }

    public function forAttorneyAndFirm(DirectoryAttorney $attorney, DirectoryFirm $firm): static
    {
        return $this->state(fn () => [
            'directory_attorney_id' => $attorney->id,
            'directory_firm_id' => $firm->id,
        ]);
    }

    public function former(): static
    {
        return $this->state(fn () => [
            'relationship_state' => DirectoryAttorneyFirmRelationshipState::Former,
            'ended_at' => now()->subMonths(3),
        ]);
    }
}
