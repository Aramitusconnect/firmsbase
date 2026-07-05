<?php

namespace Database\Factories;

use App\Models\FormDraft;
use App\Models\FormReviewChecklistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormReviewChecklistItem>
 */
class FormReviewChecklistItemFactory extends Factory
{
    protected $model = FormReviewChecklistItem::class;

    public function definition(): array
    {
        return [
            'form_draft_id' => FormDraft::factory(),
            'checklist_code' => 'names_match_intake',
            'label' => 'Names on the form match the client/matter intake data.',
            'is_checked' => false,
        ];
    }

    public function forDraft(FormDraft $draft): static
    {
        return $this->state(fn () => ['form_draft_id' => $draft->id]);
    }

    public function checked(): static
    {
        return $this->state(fn () => ['is_checked' => true, 'checked_at' => now()]);
    }
}
