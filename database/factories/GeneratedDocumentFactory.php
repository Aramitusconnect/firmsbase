<?php

namespace Database\Factories;

use App\Enums\GeneratedDocumentStatus;
use App\Models\DocumentTemplateVersion;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\GeneratedDocument;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<GeneratedDocument>
 */
class GeneratedDocumentFactory extends Factory
{
    protected $model = GeneratedDocument::class;

    /**
     * generated_documents has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_27_950029_prepare_row_level_security_
     * and_force_rls_on_generated_documents_table.php), so every INSERT
     * (test or app) must run under the row's own app.current_firm_id
     * context. See MatterExpenseFactory::create()'s docblock for the
     * full rationale, including why setDatabaseTenantContextForFirmId()
     * is used instead of setFirmContext()/runWithFirmContext() and why
     * the setting is deliberately left active rather than cleared.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);

        $models = $results instanceof Model ? new Collection([$results]) : $results;

        $service = new TenantContextService();

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

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
