<?php

namespace Database\Factories;

use App\Enums\FormMappingContentStatus;
use App\Enums\FormMappingSourceEntity;
use App\Enums\FormMappingTransform;
use App\Models\FormField;
use App\Models\FormMappingRule;
use App\Models\FormTemplateVersion;
use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormMappingRule>
 */
class FormMappingRuleFactory extends Factory
{
    protected $model = FormMappingRule::class;

    public function definition(): array
    {
        return [
            'form_field_id' => FormField::factory(),
            'form_template_version_id' => fn (array $attributes) => FormField::query()->find($attributes['form_field_id'])->form_template_version_id,
            'source_entity' => FormMappingSourceEntity::Client->value,
            'source_path' => 'client.display_name',
            'transform' => FormMappingTransform::None->value,
            'content_status' => FormMappingContentStatus::SampleOnly->value,
            'created_by_platform_admin_id' => PlatformAdmin::factory(),
        ];
    }

    public function forField(FormField $field): static
    {
        return $this->state(fn () => [
            'form_field_id' => $field->id,
            'form_template_version_id' => $field->form_template_version_id,
        ]);
    }

    public function reviewedApproved(): static
    {
        return $this->state(fn () => [
            'content_status' => FormMappingContentStatus::ReviewedApproved->value,
            'approved_by_platform_admin_id' => PlatformAdmin::factory(),
        ]);
    }
}
