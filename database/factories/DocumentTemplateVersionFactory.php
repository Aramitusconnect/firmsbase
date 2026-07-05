<?php

namespace Database\Factories;

use App\Enums\DocumentTemplateContentStatus;
use App\Enums\DocumentTemplateVersionStatus;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentTemplateVersion>
 */
class DocumentTemplateVersionFactory extends Factory
{
    protected $model = DocumentTemplateVersion::class;

    public function definition(): array
    {
        return [
            'document_template_id' => DocumentTemplate::factory(),
            'version_label' => 'v'.$this->faker->unique()->numberBetween(1, 1000),
            'status' => DocumentTemplateVersionStatus::Active->value,
            'merge_fields_schema' => [
                ['token' => 'client_name', 'source_entity' => 'client', 'source_path' => 'client.display_name', 'transform' => 'none'],
            ],
            'body_template' => 'Dear {{client_name}}, this is a deterministic sample merge template.',
            'content_status' => DocumentTemplateContentStatus::SampleOnly->value,
        ];
    }

    public function forTemplate(DocumentTemplate $template): static
    {
        return $this->state(fn () => ['document_template_id' => $template->id]);
    }

    public function reviewedApproved(): static
    {
        return $this->state(fn () => ['content_status' => DocumentTemplateContentStatus::ReviewedApproved->value]);
    }
}
