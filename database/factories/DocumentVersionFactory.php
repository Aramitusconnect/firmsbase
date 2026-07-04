<?php

namespace Database\Factories;

use App\Enums\DocumentVersionStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentVersion>
 */
class DocumentVersionFactory extends Factory
{
    protected $model = DocumentVersion::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'version_number' => 1,
            'status' => DocumentVersionStatus::Current,
            'storage_disk' => 'local',
            'storage_path' => 'documents/versions/'.$this->faker->uuid().'.pdf',
            'file_hash' => hash('sha256', $this->faker->uuid()),
            'size_bytes' => $this->faker->numberBetween(1024, 5_000_000),
            'uploaded_by' => null,
        ];
    }

    public function forDocument(Document $document, int $versionNumber = 1): static
    {
        return $this->state(fn () => ['document_id' => $document->id, 'version_number' => $versionNumber]);
    }

    public function superseded(): static
    {
        return $this->state(fn () => ['status' => DocumentVersionStatus::Superseded]);
    }
}
