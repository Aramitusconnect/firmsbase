<?php

namespace Database\Factories;

use App\Enums\TrustApprovalEventType;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TrustApprovalEvent;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @extends Factory<TrustApprovalEvent>
 */
class TrustApprovalEventFactory extends Factory
{
    protected $model = TrustApprovalEvent::class;

    /**
     * trust_approval_events has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_30_980006_prepare_row_level_security_
     * and_force_rls_on_trust_approval_events_table.php), so every
     * INSERT (test or app) must run under the row's own
     * app.current_firm_id context, even though this model does NOT use
     * BelongsToTenant. See MatterFactory::create()'s docblock for the
     * full rationale.
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
            'event_type' => TrustApprovalEventType::DepositRequested,
            'actor_firm_user_id' => FirmUser::factory(),
            'amount_cents' => 10000,
            'matter_id' => null,
            'approved_entry_type' => null,
            'correlation_uuid' => (string) Str::uuid7(),
            'trust_ledger_id' => null,
        ];
    }
}
