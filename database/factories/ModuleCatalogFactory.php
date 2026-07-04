<?php

namespace Database\Factories;

use App\Models\ModuleCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModuleCatalog>
 */
class ModuleCatalogFactory extends Factory
{
    protected $model = ModuleCatalog::class;

    public function definition(): array
    {
        return [
            'module_code' => 'module_'.$this->faker->unique()->lexify('??????'),
            'module_name' => $this->faker->words(3, true),
            'category' => 'general',
            'description' => $this->faker->sentence(),
            'is_active' => true,
            'requires_admin_approval' => false,
        ];
    }
}
