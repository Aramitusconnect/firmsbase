<?php

namespace Database\Factories;

use App\Enums\FormDraftStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\FormDraft;
use App\Models\FormTemplateVersion;
use App\Models\Matter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormDraft>
 */
class FormDraftFactory extends Factory
{
    protected $model = FormDraft::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'matter_id' => fn (array $attributes) => Matter::factory()->create(['firm_id' => $attributes['firm_id']])->id,
            'form_template_version_id' => FormTemplateVersion::factory(),
            'status' => FormDraftStatus::Draft->value,
            'used_sample_mapping' => false,
            'generated_by_firm_user_id' => fn (array $attributes) => FirmUser::factory()->create(['firm_id' => $attributes['firm_id']])->id,
        ];
    }

    public function forFirmAndMatter(Firm $firm, Matter $matter): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'matter_id' => $matter->id,
            'generated_by_firm_user_id' => FirmUser::factory()->create(['firm_id' => $firm->id])->id,
        ]);
    }

    public function withVersion(FormTemplateVersion $version): static
    {
        return $this->state(fn () => ['form_template_version_id' => $version->id]);
    }
}
