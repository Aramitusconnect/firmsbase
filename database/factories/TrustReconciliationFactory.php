<?php

namespace Database\Factories;

use App\Enums\TrustReconciliationStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TrustAccount;
use App\Models\TrustReconciliation;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<TrustReconciliation>
 */
class TrustReconciliationFactory extends Factory
{
    protected $model = TrustReconciliation::class;

    /**
     * trust_reconciliations has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_30_980008_prepare_row_level_security_
     * and_force_rls_on_trust_reconciliations_table.php), so every
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
            'trust_account_id' => TrustAccount::factory(),
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
            'system_balance_cents' => 10000,
            'asserted_bank_balance_cents' => 10000,
            'discrepancy_cents' => 0,
            'status' => TrustReconciliationStatus::Balanced,
            'performed_by_firm_user_id' => FirmUser::factory(),
            'completed_at' => now(),
        ];
    }

    public function discrepancy(int $differenceCents = 500): static
    {
        return $this->state(fn (array $attributes) => [
            'asserted_bank_balance_cents' => $attributes['system_balance_cents'] - $differenceCents,
            'discrepancy_cents' => $differenceCents,
            'status' => TrustReconciliationStatus::Discrepancy,
        ]);
    }
}
