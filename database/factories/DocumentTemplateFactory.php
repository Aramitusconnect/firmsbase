<?php

namespace Database\Factories;

use App\Enums\DocumentTemplateCategory;
use App\Enums\DocumentTemplateStatus;
use App\Models\DocumentTemplate;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentTemplate>
 */
class DocumentTemplateFactory extends Factory
{
    protected $model = DocumentTemplate::class;

    public function definition(): array
    {
        return [
            'firm_id' => null,
            'template_code' => 'template_'.$this->faker->unique()->numerify('####'),
            'name' => $this->faker->words(3, true),
            'category' => DocumentTemplateCategory::Miscellaneous->value,
            'status' => DocumentTemplateStatus::Active->value,
            'created_by_platform_admin_id' => PlatformAdmin::factory(),
        ];
    }

    public function firmSpecific(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'created_by_platform_admin_id' => null,
            'created_by_firm_user_id' => \App\Models\FirmUser::factory()->create(['firm_id' => $firm->id])->id,
        ]);
    }
}
