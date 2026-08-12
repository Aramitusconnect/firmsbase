<?php

namespace Database\Factories;

use App\Models\IntakeTemplate;
use App\Models\PracticeArea;
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
            'practice_area_id' => null,
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

    /**
     * Mission 3, checkpoint 3 — a platform-wide MyAttorney marketplace
     * intake template: no owning template pack, generic (no practice
     * area) unless forPracticeArea() is also applied.
     */
    public function marketplaceDefault(): static
    {
        return $this->state(fn () => ['template_pack_version_id' => null, 'practice_area_id' => null]);
    }

    public function forPracticeArea(PracticeArea $practiceArea): static
    {
        return $this->state(fn () => ['practice_area_id' => $practiceArea->id]);
    }
}
