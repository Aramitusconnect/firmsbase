<?php

namespace Database\Factories;

use App\Enums\FormReviewEventType;
use App\Models\FirmUser;
use App\Models\FormDraft;
use App\Models\FormReviewEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormReviewEvent>
 */
class FormReviewEventFactory extends Factory
{
    protected $model = FormReviewEvent::class;

    public function definition(): array
    {
        return [
            'form_draft_id' => FormDraft::factory(),
            'firm_id' => fn (array $attributes) => FormDraft::query()->find($attributes['form_draft_id'])->firm_id,
            'event_type' => FormReviewEventType::MarkedReadyForReview->value,
            'actor_firm_user_id' => fn (array $attributes) => FirmUser::factory()->create(['firm_id' => $attributes['firm_id']])->id,
            'created_at' => now(),
        ];
    }

    public function forDraft(FormDraft $draft): static
    {
        return $this->state(fn () => [
            'form_draft_id' => $draft->id,
            'firm_id' => $draft->firm_id,
            'actor_firm_user_id' => FirmUser::factory()->create(['firm_id' => $draft->firm_id])->id,
        ]);
    }
}
