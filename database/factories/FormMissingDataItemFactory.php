<?php

namespace Database\Factories;

use App\Models\FormDraft;
use App\Models\FormField;
use App\Models\FormMissingDataItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormMissingDataItem>
 */
class FormMissingDataItemFactory extends Factory
{
    protected $model = FormMissingDataItem::class;

    public function definition(): array
    {
        return [
            'form_draft_id' => FormDraft::factory(),
            'form_field_id' => FormField::factory(),
            'detected_at' => now(),
            'resolved_at' => null,
        ];
    }

    public function forDraft(FormDraft $draft): static
    {
        return $this->state(fn () => ['form_draft_id' => $draft->id]);
    }
}
