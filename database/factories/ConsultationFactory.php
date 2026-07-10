<?php

namespace Database\Factories;

use App\Models\Consultation;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<Consultation>
 */
class ConsultationFactory extends Factory
{
    protected $model = Consultation::class;

    /**
     * Context-hold pattern (Section 39A-3J, matching every prior
     * FORCE-RLS factory since 39A-3A): groups resolved models by
     * firm_id and activates the matching PostgreSQL session context
     * per group before inserting, so a bare
     * Consultation::factory()->create() works correctly even called
     * from outside any already-active tenant context. Deliberately
     * does not clear context afterward.
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
     * The consultation and its nested firm_lead are always tied to
     * the SAME firm — generating one firm_lead here up front (rather
     * than letting firm_id and firm_lead_id resolve as two
     * independent Firm::factory()/FirmLead::factory() calls) is
     * deliberate: a bare Consultation::factory()->create() with no
     * state must never produce a consultation whose firm_lead
     * belongs to an unrelated firm, mirroring the already-correct
     * forLead() state below and the root-cause fix already applied
     * to MatterFactory/InvoiceFactory/ConflictCheckRunFactory in
     * Sections 39A-3F/39A-3H/39A-3I.
     */
    public function definition(): array
    {
        $lead = FirmLead::factory()->create();

        return [
            'firm_id' => $lead->firm_id,
            'firm_lead_id' => $lead->id,
            'consultation_outcome_id' => null,
            'scheduled_at' => now()->addDay(),
            'held_at' => null,
            'notes' => $this->faker->sentence(),
            'converted' => false,
        ];
    }

    /**
     * Root-cause fix (Section 39A-3J follow-up): mirrors
     * MatterFactory::forFirm()/InvoiceFactory::forFirm()/
     * ConflictCheckRunFactory::forFirm() — override both firm_id AND
     * the nested tenant-owned FK (firm_lead_id) together, deriving the
     * lead from a FirmLead::factory()->forFirm($firm) that is
     * guaranteed to belong to $firm. Without this, definition()'s
     * independently-created FirmLead would be overwritten only on
     * firm_id, leaving firm_lead_id pointing at an unrelated firm.
     *
     * Precedence: if forLead() is applied after forFirm() (or vice
     * versa), the LAST state applied wins for both firm_id and
     * firm_lead_id, since each state fully re-derives both keys as a
     * consistent pair rather than patching one field in isolation.
     * Callers should not chain forFirm()->forLead() or
     * forLead()->forFirm() when the lead does not belong to the given
     * firm; use one or the other, not both, unless intentionally
     * relying on last-call-wins.
     */
    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'firm_lead_id' => FirmLead::factory()->forFirm($firm),
        ]);
    }

    /**
     * forLead() remains the authority for a caller that already has a
     * specific FirmLead: both firm_id and firm_lead_id are derived
     * from that lead, unchanged from prior behavior.
     */
    public function forLead(FirmLead $lead): static
    {
        return $this->state(fn () => ['firm_id' => $lead->firm_id, 'firm_lead_id' => $lead->id]);
    }

    public function held(): static
    {
        return $this->state(fn () => ['held_at' => now()]);
    }
}
