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
        $service = new TenantContextService();

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    /**
     * The event and its nested payment plan are always tied to the SAME
     * firm — generating one firm here up front (rather than letting
     * firm_id and payment_plan_id resolve as two independent
     * Firm::factory()/PaymentPlan::factory() calls) is deliberate: a
     * bare PaymentPlanEvent::factory()->create() with no state must
     * never produce an event whose payment_plan belongs to an unrelated
     * firm. payment_plan_id is NOT NULL on this table, so this fix
     * matters even for the bare default path. Matches the same
     * root-cause fix already applied to PaymentPlanFactory (Checkpoint
     * 22).
     */
    public function definition(): array
    {
        $firm = Firm::factory()->create();

        return [
            'firm_id' => $firm->id,
            'payment_plan_id' => PaymentPlan::factory()->forFirm($firm),
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
