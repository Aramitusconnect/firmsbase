<?php

namespace Database\Factories;

use App\Enums\DeploymentHealthReportMode;
use App\Enums\HealthCheckStatus;
use App\Models\DeploymentHealthCheck;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<DeploymentHealthCheck>
 */
class DeploymentHealthCheckFactory extends Factory
{
    protected $model = DeploymentHealthCheck::class;

    /**
     * deployment_health_checks has permanent FORCE ROW LEVEL SECURITY
     * (see database/migrations/2026_08_28_960006_prepare_row_level_
     * security_and_force_rls_on_deployment_health_checks_table.php), so
     * every INSERT (test or app) must run under the row's own
     * app.current_firm_id context. See MatterFactory::create()'s
     * docblock for the full rationale, including why
     * setDatabaseTenantContextForFirmId() is used instead of
     * setFirmContext()/runWithFirmContext() and why the setting is
     * deliberately left active rather than cleared.
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
            'heartbeat_at' => now(),
            'version' => '2026.7.0',
            'migration_status' => 'completed',
            'status' => HealthCheckStatus::Healthy,
            'reported_via' => DeploymentHealthReportMode::Live,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function offlineReport(): static
    {
        return $this->state(fn () => ['reported_via' => DeploymentHealthReportMode::OfflineReport]);
    }
}
