<?php

namespace Database\Factories;

use App\Enums\TrustRefundRequestStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TrustLedger;
use App\Models\TrustRefundRequest;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<TrustRefundRequest>
 */
class TrustRefundRequestFactory extends Factory
{
    protected $model = TrustRefundRequest::class;

    /**
     * trust_refund_requests has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_30_980009_prepare_row_level_security_
     * and_force_rls_on_trust_refund_requests_table.php), so every
     * INSERT (test or app) must run under the row's own
     * app.current_firm_id context. See MatterFactory::create()'s
     * docblock for the full rationale.
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
            'trust_ledger_id' => TrustLedger::factory(),
            'matter_id' => null,
            'amount_cents' => 2500,
            'status' => TrustRefundRequestStatus::Requested,
            'requested_by_firm_user_id' => FirmUser::factory(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => TrustRefundRequestStatus::Approved,
            'approved_by_firm_user_id' => FirmUser::factory(),
        ]);
    }
}
