<?php

namespace Database\Factories;

use App\Enums\DocumentRequestItemStatus;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRequestItem>
 */
class DocumentRequestItemFactory extends Factory
{
    protected $model = DocumentRequestItem::class;

    public function definition(): array
    {
        return [
            'document_request_id' => DocumentRequest::factory(),
            'label' => $this->faker->randomElement(['Passport copy', 'Birth certificate', 'I-94 record', 'Employment letter']),
            'status' => DocumentRequestItemStatus::Requested,
            'is_required' => true,
            'viewed_at' => null,
            'submitted_at' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejected_reason' => null,
            'waived_by' => null,
            'waived_at' => null,
            'expires_at' => null,
        ];
    }

    public function forRequest(DocumentRequest $request): static
    {
        return $this->state(fn () => ['document_request_id' => $request->id]);
    }

    public function submitted(): static
    {
        return $this->state(fn () => [
            'status' => DocumentRequestItemStatus::Submitted,
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => DocumentRequestItemStatus::Approved,
            'submitted_at' => now()->subDay(),
            'reviewed_at' => now(),
        ]);
    }
}
