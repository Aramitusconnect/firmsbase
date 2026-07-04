<?php

namespace Database\Factories;

use App\Models\IntakeTemplate;
use App\Models\TemplatePackVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntakeTemplate>
 */
class IntakeTemplateFactory extends Factory
{
    protected $model = IntakeTemplate::class;

    public function definition(): array
    {
        return [
            'template_pack_version_id' => TemplatePackVersion::factory(),
            'matter_type_id' => null,
            'code' => $this->faker->unique()->slug(2, false),
            'name' => $this->faker->words(3, true),
            'schema_json' => ['fields' => []],
            'is_active' => true,
        ];
    }

    public function forVersion(TemplatePackVersion $version): static
    {
        return $this->state(fn () => ['template_pack_version_id' => $version->id]);
    }
}
