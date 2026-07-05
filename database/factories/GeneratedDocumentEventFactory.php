<?php

namespace Database\Factories;

use App\Enums\GeneratedDocumentEventType;
use App\Models\FirmUser;
use App\Models\GeneratedDocument;
use App\Models\GeneratedDocumentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeneratedDocumentEvent>
 */
class GeneratedDocumentEventFactory extends Factory
{
    protected $model = GeneratedDocumentEvent::class;

    public function definition(): array
    {
        return [
            'generated_document_id' => GeneratedDocument::factory(),
            'firm_id' => fn (array $attributes) => GeneratedDocument::query()->find($attributes['generated_document_id'])->firm_id,
            'event_type' => GeneratedDocumentEventType::MarkedReadyForReview->value,
            'actor_firm_user_id' => fn (array $attributes) => FirmUser::factory()->create(['firm_id' => $attributes['firm_id']])->id,
            'created_at' => now(),
        ];
    }

    public function forDocument(GeneratedDocument $document): static
    {
        return $this->state(fn () => [
            'generated_document_id' => $document->id,
            'firm_id' => $document->firm_id,
            'actor_firm_user_id' => FirmUser::factory()->create(['firm_id' => $document->firm_id])->id,
        ]);
    }
}
