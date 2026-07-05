<?php

namespace Database\Factories;

use App\Enums\SignatureRequestStatus;
use App\Enums\SignatureSourceDocumentType;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\SignatureRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignatureRequest>
 */
class SignatureRequestFactory extends Factory
{
    protected $model = SignatureRequest::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'source_document_type' => SignatureSourceDocumentType::Document->value,
            'document_id' => fn (array $attributes) => Document::factory()->create(['firm_id' => $attributes['firm_id']])->id,
            'status' => SignatureRequestStatus::Draft->value,
            'title' => $this->faker->sentence(4),
            'requested_by_firm_user_id' => fn (array $attributes) => FirmUser::factory()->create(['firm_id' => $attributes['firm_id']])->id,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'document_id' => Document::factory()->create(['firm_id' => $firm->id])->id,
            'requested_by_firm_user_id' => FirmUser::factory()->create(['firm_id' => $firm->id])->id,
        ]);
    }

    public function attorneyReviewed(): static
    {
        return $this->state(fn (array $attributes) => [
            'attorney_reviewed_at' => now(),
            'attorney_reviewed_by_firm_user_id' => FirmUser::factory()->create(['firm_id' => $attributes['firm_id']])->id,
            'attorney_review_notes' => 'Reviewed for ESIGN/UETA suitability.',
        ]);
    }

    public function status(SignatureRequestStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }
}
