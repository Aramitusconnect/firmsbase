<?php

namespace Database\Factories;

use App\Enums\FormFieldType;
use App\Models\FormField;
use App\Models\FormTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormField>
 */
class FormFieldFactory extends Factory
{
    protected $model = FormField::class;

    public function definition(): array
    {
        return [
            'form_template_version_id' => FormTemplateVersion::factory(),
            'field_code' => 'field_'.$this->faker->unique()->numerify('####'),
            'field_label' => $this->faker->words(3, true),
            'field_type' => FormFieldType::Text->value,
            'is_required' => false,
            'sort_order' => 0,
        ];
    }

    public function forVersion(FormTemplateVersion $version): static
    {
        return $this->state(fn () => ['form_template_version_id' => $version->id]);
    }

    public function required(): static
    {
        return $this->state(fn () => ['is_required' => true]);
    }
}
