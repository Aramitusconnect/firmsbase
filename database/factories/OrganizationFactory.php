<?php

namespace Database\Factories;

use App\Enums\ConflictScope;
use App\Enums\RecordStatus;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'legal_name' => $this->faker->company().' LLC',
            'status' => RecordStatus::Active,
            'primary_contact' => $this->faker->safeEmail(),
            'conflict_scope' => ConflictScope::FirmScoped,
        ];
    }
}
