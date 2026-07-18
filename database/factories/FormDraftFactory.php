<?php

namespace Database\Factories;

use App\Enums\FormDraftStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\FormDraft;
use App\Models\FormTemplateVersion;
use App\Models\Matter;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<FormDraft>
 */
class FormDraftFactory extends Factory
{
    protected $model = FormDraft::class;

    /**
     * form_drafts has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_27_950030_prepare_row_level_security_
     * and_force_rls_on_form_drafts_table.php), so every INSERT (test or
     * app) must run under the row's own app.current_firm_id context.
     * See MatterExpenseFactory::create()'s docblock for the full
     * rationale, including why setDatabaseTenantContextForFirmId() is
     * used instead of setFirmContext()/runWithFirmContext() and why the
     * setting is deliberately left active rather than cleared.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);

        $models = $results instanceof Model ? new Collection([$results]) : $results;

        $service = new TenantContextService();

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

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
