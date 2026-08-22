<?php

namespace Database\Factories;

use App\Enums\KeyDestructionRequestStatus;
use App\Models\Firm;
use App\Models\KeyDestructionRequest;
use App\Models\PlatformAdmin;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<KeyDestructionRequest>
 */
class KeyDestructionRequestFactory extends Factory
{
    protected $model = KeyDestructionRequest::class;

    /**
     * key_destruction_requests has permanent FORCE ROW LEVEL SECURITY
     * (see database/migrations/2026_08_28_960003_prepare_row_level_
     * security_and_force_rls_on_key_destruction_requests_table.php), so
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
            'offboarding_request_id' => null,
            'tenant_encryption_key_id' => null,
            'status' => KeyDestructionRequestStatus::Requested,
            'reason' => 'Firm offboarding complete; destroying envelope encryption key.',
            'requested_by_platform_admin_id' => PlatformAdmin::factory(),
            'requested_at' => now(),
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
