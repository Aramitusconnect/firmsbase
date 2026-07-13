<?php

namespace Database\Factories;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\IncidentEvent;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @extends Factory<IncidentEvent>
 */
class IncidentEventFactory extends Factory
{
    protected $model = IncidentEvent::class;

    public function definition(): array
    {
        return [
            'firm_id' => null,
            'correlation_id' => (string) Str::uuid(),
            'event_type' => 'opened',
            'severity' => IncidentSeverity::Medium,
            'status' => IncidentStatus::Investigating,
            'customer_impact' => false,
            'notification_needed' => false,
            'root_cause' => null,
            'resolution' => null,
            'message' => 'Simulated incident.',
            'actor_user_id' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['event_type' => 'resolved', 'status' => IncidentStatus::Resolved, 'resolution' => 'Fixed.']);
    }

    /**
     * Section 39A-3L Phase B6 — same fix as HealthCheckFactory/
     * BackupRestoreTestFactory, not a verbatim ClientFactory/
     * ContactFactory copy: this table's own default state is
     * firm_id = null, so the null-firm_id group explicitly calls
     * clearDatabaseTenantContext() before store() rather than assuming
     * absence of context (a direct, unconditional clear, not
     * runWithoutFirmContext()'s save/restore wrapper) — correct here
     * because a factory create() call is always the outermost
     * tenant-context operation for the row(s) it is producing at that
     * moment.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);

        $models = $results instanceof Model ? new Collection([$results]) : $results;

        $service = app(TenantContextService::class);

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $firmId = $group->first()->firm_id;

            if ($firmId === null) {
                $service->clearDatabaseTenantContext();
                $this->store($group);

                return;
            }

            $service->setDatabaseTenantContextForFirmId($firmId);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }
}
