<?php

namespace Database\Factories;

use App\Enums\SupportAccessRequestStatus;
use App\Enums\SupportAccessType;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<SupportAccessRequest>
 */
class SupportAccessRequestFactory extends Factory
{
    protected $model = SupportAccessRequest::class;

    /**
     * support_access_requests has permanent FORCE ROW LEVEL SECURITY
     * (see database/migrations/2026_08_28_960004_prepare_row_level_
     * security_and_force_rls_on_support_access_requests_table.php), so
     * every INSERT (test or app) must run under the row's own
     * app.current_firm_id context, despite this model not using
     * BelongsToTenant — RLS operates at the DB layer regardless of
     * Eloquent trait usage. See MatterFactory::create()'s docblock for
     * the full rationale, including why
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
            'requested_by' => PlatformAdmin::factory(),
            'access_type' => SupportAccessType::Standard->value,
            'reason' => 'Investigating a client-reported billing discrepancy.',
            'status' => SupportAccessRequestStatus::Requested->value,
            'requested_duration_minutes' => 60,
        ];
    }

    public function emergency(): static
    {
        return $this->state(fn () => [
            'access_type' => SupportAccessType::Emergency->value,
            'emergency_justification' => 'Production incident requiring immediate platform staff access.',
        ]);
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
