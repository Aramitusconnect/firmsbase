<?php

namespace Database\Factories;

use App\Enums\FormDraftValueSource;
use App\Models\FormDraft;
use App\Models\FormDraftValue;
use App\Models\FormField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormDraftValue>
 */
class FormDraftValueFactory extends Factory
{
    protected $model = FormDraftValue::class;

    public function definition(): array
    {
        return [
            'form_draft_id' => FormDraft::factory(),
            'form_field_id' => FormField::factory(),
            'form_mapping_rule_id' => null,
            'value' => $this->faker->word(),
            'source' => FormDraftValueSource::ManualOverride->value,
        ];
    }

    public function forDraft(FormDraft $draft): static
    {
        return $this->state(fn () => ['form_draft_id' => $draft->id]);
    }
}
