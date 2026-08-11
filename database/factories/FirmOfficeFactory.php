<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\FirmOffice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FirmOffice>
 */
class FirmOfficeFactory extends Factory
{
    protected $model = FirmOffice::class;

    public function definition(): array
    {
        $city = $this->faker->randomElement(['Detroit', 'Ann Arbor', 'Grand Rapids', 'Lansing', 'Traverse City']);

        return [
            'directory_firm_id' => DirectoryFirm::factory(),
            'label' => null,
            'address_line1' => $this->faker->streetAddress(),
            'address_line2' => null,
            'city' => $city,
            'city_normalized' => Str::lower($city),
            'state' => 'MI',
            'country' => 'US',
            'postal_code' => $this->faker->numerify('4####'),
            'phone' => $this->faker->numerify('##########'),
            'latitude' => null,
            'longitude' => null,
            'is_primary' => true,
            'appointment_only' => false,
            'published' => true,
            'source_type' => DataProvenanceSourceType::AdminEntered,
            'source_reference' => null,
            'last_verified_at' => null,
        ];
    }

    public function forFirm(DirectoryFirm $firm): static
    {
        return $this->state(fn () => ['directory_firm_id' => $firm->id]);
    }

    public function withCoordinates(float $lat, float $lng): static
    {
        return $this->state(fn () => ['latitude' => $lat, 'longitude' => $lng]);
    }

    public function unpublished(): static
    {
        return $this->state(fn () => ['published' => false]);
    }
}
