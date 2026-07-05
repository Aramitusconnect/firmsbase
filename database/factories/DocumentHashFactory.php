<?php

namespace Database\Factories;

use App\Enums\HashAlgorithm;
use App\Enums\SignatureSourceDocumentType;
use App\Models\Document;
use App\Models\DocumentHash;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentHash>
 */
class DocumentHashFactory extends Factory
{
    protected $model = DocumentHash::class;

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

    public function forGeneratedDocument(\App\Models\GeneratedDocument $generatedDocument): static
    {
        return $this->state(fn () => [
            'firm_id' => $generatedDocument->firm_id,
            'source_document_type' => SignatureSourceDocumentType::GeneratedDocument->value,
            'document_id' => null,
            'generated_document_id' => $generatedDocument->id,
        ]);
    }
}
