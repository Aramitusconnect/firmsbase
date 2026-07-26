<?php

namespace Database\Factories;

use App\Models\Firm;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanEvent;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<PaymentPlanEvent>
 */
class PaymentPlanEventFactory extends Factory
{
    protected $model = PaymentPlanEvent::class;

    /**
     * Section 39A-3L, Checkpoint 23 — context-hold pattern (matching
     * every prior FORCE-RLS factory since 39A-3A): groups resolved
     * models by firm_id and activates the matching PostgreSQL session
     * context per group before inserting, so a bare
     * PaymentPlanEvent::factory()->create() works correctly even called
     * from outside any already-active tenant context.
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
     * The event and its nested payment plan are always tied to the SAME
     * firm. payment_plan_id is NOT NULL on this table, so this matters
     * even for the bare default path.
     *
     * Audit fix (eager-factory-side-effects audit): this used to call
     * Firm::factory()->create() as a plain PHP statement at the top of
     * definition() — a real, committed Firm every single time, even
     * when forPlan() below immediately overrides both firm_id and
     * payment_plan_id with a caller-supplied plan. Fixed by making
     * firm_id Laravel's own lazy factory-relationship form;
     * payment_plan_id remains a lazy, uncreated Factory instance
     * derived from it.
     */
    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'payment_plan_id' => fn (array $attributes) => PaymentPlan::factory()
                ->forFirm(Firm::query()->findOrFail($attributes['firm_id'])),
            'event_type' => 'created',
            'metadata_json' => [],
            'actor_user_id' => null,
        ];
    }

    public function forPlan(PaymentPlan $plan): static
    {
        return $this->state(fn () => ['firm_id' => $plan->firm_id, 'payment_plan_id' => $plan->id]);
    }

    public function eventType(string $type): static
    {
        return $this->state(fn () => ['event_type' => $type]);
    }
}
