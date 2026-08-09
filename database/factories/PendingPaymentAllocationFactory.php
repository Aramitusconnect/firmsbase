<?php

namespace Database\Factories;

use App\Enums\PendingPaymentAllocationStatus;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PendingPaymentAllocation;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<PendingPaymentAllocation>
 */
class PendingPaymentAllocationFactory extends Factory
{
    protected $model = PendingPaymentAllocation::class;

    /**
     * payment_pending_allocations has permanent FORCE ROW LEVEL
     * SECURITY, so every INSERT must run under the row's own
     * app.current_firm_id context — see MatterFactory::create()'s
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
            'payment_id' => Payment::factory(),
            'invoice_id' => Invoice::factory(),
            'amount_cents' => $this->faker->numberBetween(1000, 50000),
            'status' => PendingPaymentAllocationStatus::Pending,
            'reason' => 'ambiguous_partial_payment_on_mixed_invoice',
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
