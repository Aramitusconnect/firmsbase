<?php

namespace Database\Factories;

use App\Models\FirmEntitlement;
use App\Models\FirmEntitlementEvent;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<FirmEntitlementEvent>
 */
class FirmEntitlementEventFactory extends Factory
{
    protected $model = FirmEntitlementEvent::class;

    /**
     * Section 39A-3L, Checkpoint 5 — context-hold pattern (matching
     * FirmEntitlementFactory/FirmActivationEventFactory from earlier
     * checkpoints in this arc): groups the FINAL resolved models (i.e.
     * after any forEntitlement() state has been applied on top of
     * definition()'s default) by firm_id and activates the matching
     * PostgreSQL session context per group immediately before inserting.
     * This is deliberately NOT the PaymentClassificationEventFactory
     * pattern (eager nested Payment::factory()->create() inside
     * definition() with no create() override) — that pattern leaves the
     * session context wherever the eagerly-created nested model's own
     * factory last set it, which is wrong for callers that override
     * firm_id via forPayment()/forEntitlement() state after the fact.
     * Here, the group-by-firm_id step re-derives context from the
     * model's actual final attribute value, so it is correct regardless
     * of which path (bare default or forEntitlement()) produced it.
     * Deliberately does not clear context afterward.
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
     * The event and its firm_entitlement are always tied to the SAME
     * firm — generating one FirmEntitlement here up front (rather than
     * letting firm_entitlement_id and firm_id resolve as two
     * independent FirmEntitlement::factory()/Firm::factory() chains) is
     * deliberate: a bare FirmEntitlementEvent::factory()->create() with
     * no state must never produce an event whose firm_id disagrees with
     * its own firm_entitlement's firm_id — that exact mismatch was
     * empirically confirmed (event.firm_id != firmEntitlement.firm_id)
     * before this fix.
     */
    public function definition(): array
    {
        $entitlement = FirmEntitlement::factory()->create();

        return [
            'firm_entitlement_id' => $entitlement->id,
            'firm_id' => $entitlement->firm_id,
            'module_code' => $entitlement->module_code,
            'source' => $entitlement->source instanceof \BackedEnum
                ? $entitlement->source->value
                : $entitlement->source,
            'action' => 'granted',
            'reason' => null,
            'actor_type' => 'System',
            'actor_id' => null,
            'metadata' => [],
        ];
    }

    public function forEntitlement(FirmEntitlement $entitlement): static
    {
        return $this->state(fn () => [
            'firm_entitlement_id' => $entitlement->id,
            'firm_id' => $entitlement->firm_id,
            'module_code' => $entitlement->module_code,
            'source' => $entitlement->source instanceof \BackedEnum
                ? $entitlement->source->value
                : $entitlement->source,
        ]);
    }
}
