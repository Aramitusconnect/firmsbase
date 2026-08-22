<?php

namespace Database\Factories;

use App\Enums\MigrationProjectStatus;
use App\Enums\MigrationSourceType;
use App\Models\Firm;
use App\Models\MigrationProject;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<MigrationProject>
 */
class MigrationProjectFactory extends Factory
{
    protected $model = MigrationProject::class;

    /**
     * migration_projects has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_29_970002_prepare_row_level_security_
     * and_force_rls_on_migration_projects_table.php), so every INSERT
     * (test or app) must run under the row's own app.current_firm_id
     * context. See MatterFactory::create()'s docblock for the full
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
        $service = new TenantContextService;

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
            'source_type' => MigrationSourceType::Spreadsheets->value,
            'status' => MigrationProjectStatus::Draft->value,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function sourceType(MigrationSourceType $type): static
    {
        return $this->state(fn () => ['source_type' => $type->value]);
    }
}
