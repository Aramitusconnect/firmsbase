<?php

namespace Database\Factories;

use App\Enums\TrustLedgerStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\TrustAccount;
use App\Models\TrustLedger;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<TrustLedger>
 */
class TrustLedgerFactory extends Factory
{
    protected $model = TrustLedger::class;

    /**
     * trust_ledgers has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_30_980002_prepare_row_level_security_
     * and_force_rls_on_trust_ledgers_table.php), so every INSERT (test
     * or app) must run under the row's own app.current_firm_id context.
     * See MatterFactory::create()'s docblock for the full rationale.
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
            'trust_account_id' => TrustAccount::factory(),
            'client_id' => Client::factory(),
            'status' => TrustLedgerStatus::Active,
        ];
    }

    public function frozen(): static
    {
        return $this->state(fn () => ['status' => TrustLedgerStatus::Frozen]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => TrustLedgerStatus::Closed]);
    }
}
