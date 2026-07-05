<?php

namespace Database\Factories;

use App\Enums\FormEditionWatchStatus;
use App\Models\FormEditionWatchItem;
use App\Models\FormTemplate;
use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormEditionWatchItem>
 */
class FormEditionWatchItemFactory extends Factory
{
    protected $model = FormEditionWatchItem::class;

    public function definition(): array
    {
        return [
            'form_template_id' => FormTemplate::factory(),
            'watch_status' => FormEditionWatchStatus::Watching->value,
            'created_by_platform_admin_id' => PlatformAdmin::factory(),
        ];
    }

    public function forFormTemplate(FormTemplate $formTemplate): static
    {
        return $this->state(fn () => ['form_template_id' => $formTemplate->id]);
    }
}
