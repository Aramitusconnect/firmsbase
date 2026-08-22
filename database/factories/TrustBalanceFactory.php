<?php

namespace Database\Factories;

use App\Models\Firm;
use App\Models\TrustBalance;
use App\Models\TrustLedger;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<TrustBalance>
 */
class TrustBalanceFactory extends Factory
{
    protected $model = TrustBalance::class;

    /**
     * trust_balances has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_30_980003_prepare_row_level_security_
     * and_force_rls_on_trust_balances_table.php), so every INSERT (test
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
            'trust_ledger_id' => TrustLedger::factory(),
            'balance_cents' => 0,
            'last_recomputed_at' => now(),
        ];
    }

    public function forLedger(TrustLedger $ledger): static
    {
        return $this->state(fn () => [
            'firm_id' => $ledger->firm_id,
            'trust_ledger_id' => $ledger->id,
        ]);
    }
}
