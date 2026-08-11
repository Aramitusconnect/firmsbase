<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\DirectoryAttorney;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DirectoryAttorney>
 */
class DirectoryAttorneyFactory extends Factory
{
    protected $model = DirectoryAttorney::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->name();

        return [
            'slug' => DirectoryAttorney::generateUniqueSlug($name),
            'name' => $name,
            'name_normalized' => Str::lower($name),
            'title' => 'Attorney',
            'biography' => $this->faker->paragraph(),
            'photo_path' => null,
            'bar_number' => null,
            'license_jurisdictions' => ['MI'],
            'publication_state' => DirectoryPublicationState::Published,
            'source_type' => DataProvenanceSourceType::AdminEntered,
            'source_reference' => null,
            'imported_at' => now(),
            'last_verified_at' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['publication_state' => DirectoryPublicationState::Draft]);
    }
}
