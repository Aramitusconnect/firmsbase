<?php

namespace Database\Factories;

use App\Enums\PaymentPlanStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\PaymentPlan;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<PaymentPlan>
 */
class PaymentPlanFactory extends Factory
{
    protected $model = PaymentPlan::class;

    /**
     * Section 39A-3L, Checkpoint 22 — context-hold pattern (matching
     * every prior FORCE-RLS factory since 39A-3A): groups resolved
     * models by firm_id and activates the matching PostgreSQL session
     * context per group before inserting, so a bare
     * PaymentPlan::factory()->create() works correctly even called from
     * outside any already-active tenant context.
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

    /**
     * The plan and its nested client are always tied to the SAME firm.
     * client_id is NOT NULL on this table, so this matters even for the
     * bare default path.
     *
     * Audit fix (eager-factory-side-effects audit): this used to call
     * Firm::factory()->create() as a plain PHP statement at the top of
     * definition() — a real, committed Firm every single time, even
     * when forFirm()/forClient() below immediately override both
     * firm_id and client_id with a caller-supplied firm. Fixed by
     * making firm_id Laravel's own lazy factory-relationship form;
     * client_id remains a lazy, uncreated Factory instance derived from
     * it.
     */
    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'client_id' => fn (array $attributes) => Client::factory()
                ->forFirm(Firm::query()->findOrFail($attributes['firm_id'])),
            'matter_id' => null,
            'invoice_id' => null,
            'status' => PaymentPlanStatus::Draft,
            'total_cents' => 0,
            'currency' => 'usd',
            'installment_count' => 0,
            'supersedes_payment_plan_id' => null,
            'created_by' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'client_id' => Client::factory()->forFirm($firm),
        ]);
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn () => [
            'firm_id' => $client->firm_id,
            'client_id' => $client->id,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => PaymentPlanStatus::Active, 'activated_at' => now()]);
    }
}
