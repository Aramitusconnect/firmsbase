<?php

namespace Database\Factories;

use App\Enums\FormTemplateVersionStatus;
use App\Models\FormTemplate;
use App\Models\FormTemplateVersion;
use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormTemplateVersion>
 */
class FormTemplateVersionFactory extends Factory
{
    protected $model = FormTemplateVersion::class;

    public function definition(): array
    {
        return [
            'form_template_id' => FormTemplate::factory(),
            'edition_date' => $this->faker->date(),
            'status' => FormTemplateVersionStatus::Active->value,
            'created_by_platform_admin_id' => PlatformAdmin::factory(),
        ];
    }

    public function forFormTemplate(FormTemplate $formTemplate): static
    {
        return $this->state(fn () => ['form_template_id' => $formTemplate->id]);
    }

    public function retired(): static
    {
        return $this->state(fn () => [
            'status' => FormTemplateVersionStatus::Retired->value,
            'retired_at' => now(),
            'retired_reason' => 'edition superseded',
        ]);
    }
}
