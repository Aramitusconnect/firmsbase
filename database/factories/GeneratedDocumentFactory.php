<?php

namespace Database\Factories;

use App\Enums\GeneratedDocumentStatus;
use App\Models\DocumentTemplateVersion;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\GeneratedDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeneratedDocument>
 */
class GeneratedDocumentFactory extends Factory
{
    protected $model = GeneratedDocument::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'document_template_version_id' => DocumentTemplateVersion::factory(),
            'status' => GeneratedDocumentStatus::Draft->value,
            'simulated_storage_path' => 'generated-documents/fixture/'.$this->faker->uuid().'.pdf',
            'used_sample_content' => false,
            'generated_by_firm_user_id' => fn (array $attributes) => FirmUser::factory()->create(['firm_id' => $attributes['firm_id']])->id,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'generated_by_firm_user_id' => FirmUser::factory()->create(['firm_id' => $firm->id])->id,
        ]);
    }

    public function withTemplateVersion(DocumentTemplateVersion $version): static
    {
        return $this->state(fn () => ['document_template_version_id' => $version->id]);
    }
}
