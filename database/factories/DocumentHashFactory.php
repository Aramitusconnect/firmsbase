<?php

namespace Database\Factories;

use App\Enums\HashAlgorithm;
use App\Enums\SignatureSourceDocumentType;
use App\Models\Document;
use App\Models\DocumentHash;
use App\Models\Firm;
use App\Models\GeneratedDocument;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<DocumentHash>
 */
class DocumentHashFactory extends Factory
{
    protected $model = DocumentHash::class;

    /**
     * document_hashes has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_27_950033_prepare_row_level_security_
     * and_force_rls_on_document_hashes_table.php), so every INSERT
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

        $service = new TenantContextService;

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
            'source_document_type' => SignatureSourceDocumentType::Document->value,
            'document_id' => fn (array $attributes) => Document::factory()->create(['firm_id' => $attributes['firm_id']])->id,
            'algorithm' => HashAlgorithm::Sha256->value,
            'hash_value' => hash('sha256', $this->faker->uuid()),
            'recorded_at' => now(),
        ];
    }

    public function forDocument(Document $document): static
    {
        return $this->state(fn () => [
            'firm_id' => $document->firm_id,
            'source_document_type' => SignatureSourceDocumentType::Document->value,
            'document_id' => $document->id,
            'generated_document_id' => null,
        ]);
    }

    public function forGeneratedDocument(GeneratedDocument $generatedDocument): static
    {
        return $this->state(fn () => [
            'firm_id' => $generatedDocument->firm_id,
            'source_document_type' => SignatureSourceDocumentType::GeneratedDocument->value,
            'document_id' => null,
            'generated_document_id' => $generatedDocument->id,
        ]);
    }
}
