<?php

namespace Database\Factories;

use App\Enums\TrustChargebackStatus;
use App\Models\Firm;
use App\Models\TrustChargebackEvent;
use App\Models\TrustLedgerEntry;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<TrustChargebackEvent>
 */
class TrustChargebackEventFactory extends Factory
{
    protected $model = TrustChargebackEvent::class;

    /**
     * trust_chargeback_events has permanent FORCE ROW LEVEL SECURITY
     * (see database/migrations/2026_08_30_980007_prepare_row_level_
     * security_and_force_rls_on_trust_chargeback_events_table.php), so
     * every INSERT (test or app) must run under the row's own
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
            'original_trust_ledger_entry_id' => TrustLedgerEntry::factory(),
            'amount_cents' => 10000,
            'reason' => 'Client disputed the deposit with their card issuer.',
            'status' => TrustChargebackStatus::Reported,
            'reported_at' => now(),
        ];
    }

    public function reversed(): static
    {
        return $this->state(fn () => [
            'status' => TrustChargebackStatus::Reversed,
            'reversal_trust_ledger_entry_id' => TrustLedgerEntry::factory(),
        ]);
    }
}
