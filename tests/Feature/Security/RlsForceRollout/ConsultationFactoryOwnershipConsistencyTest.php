<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Consultation;
use App\Models\Firm;
use App\Models\FirmLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ConsultationFactoryOwnershipConsistencyTest — Section 39A-3J
 * follow-up. Proves the just-landed root-cause fix to
 * database/factories/ConsultationFactory.php::forFirm() (setting both
 * firm_id AND a firm_lead_id derived from
 * FirmLead::factory()->forFirm($firm), mirroring the established
 * MatterFactory/InvoiceFactory/ConflictCheckRunFactory sibling
 * pattern) behaves correctly across every code path that can produce
 * a Consultation: the bare default definition(), the explicit
 * forFirm() state, and the explicit forLead() state — and that none
 * of the three can ever produce a firm_id/firmLead->firm_id
 * cross-firm mismatch.
 *
 * This is a factory-correctness proof, not a duplicate of the RLS
 * activation proofs already covered by ConsultationsForceRlsActivationTest
 * (FORCE enablement, missing-context denial, cross-firm read/update/
 * delete/insert denial, migration down()/up(), etc.) — this file is
 * scoped purely to ConsultationFactory's own state-derivation
 * correctness.
 */
class ConsultationFactoryOwnershipConsistencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Proof 1: a bare Consultation::factory()->create() with no
     * explicit state must generate a firm_lead tied to the SAME firm
     * as the consultation's own firm_id — read back the firm_lead
     * under its own firm's tenant context (rather than assuming) and
     * compare firm_id.
     */
    public function test_default_factory_creation_is_internally_consistent(): void
    {
        $consultation = Consultation::factory()->create();

        $this->assertNotNull($consultation->firm_id);
        $this->assertNotNull($consultation->firm_lead_id);

        $leadFirmId = $this->runWithFirmContext(
            $consultation->firm_id,
            fn () => FirmLead::withoutGlobalScopes()->find($consultation->firm_lead_id)
        )->firm_id;

        $this->assertSame(
            $consultation->firm_id,
            $leadFirmId,
            'A bare Consultation::factory()->create() must never produce a firm_id/firmLead->firm_id mismatch.'
        );
    }

    /**
     * Proof 2: forFirm($firm) must both set firm_id to the given firm
     * AND associate/create a lead that itself belongs to that same
     * firm — not merely overwrite firm_id while leaving firm_lead_id
     * pointing at an unrelated, independently-resolved firm.
     */
    public function test_for_firm_state_creates_a_lead_belonging_to_the_specified_firm(): void
    {
        $firm = Firm::factory()->create();

        $consultation = Consultation::factory()->forFirm($firm)->create();

        $this->assertSame($firm->id, $consultation->firm_id);

        $leadFirmId = $this->runWithFirmContext(
            $firm,
            fn () => FirmLead::withoutGlobalScopes()->find($consultation->firm_lead_id)
        )->firm_id;

        $this->assertSame(
            $firm->id,
            $leadFirmId,
            'forFirm($firm) must create/associate a firm_lead that itself belongs to $firm, not an unrelated firm.'
        );
    }

    /**
     * Proof 3: forLead($lead) must derive the consultation's firm_id
     * from the supplied lead's own firm_id — not from any
     * independently-generated firm.
     */
    public function test_for_lead_state_derives_firm_from_the_supplied_lead(): void
    {
        $firm = Firm::factory()->create();
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->forFirm($firm)->create());

        $consultation = $this->runWithFirmContext($firm, fn () => Consultation::factory()->forLead($lead)->create());

        $this->assertSame($lead->firm_id, $consultation->firm_id);
        $this->assertSame($lead->id, $consultation->firm_lead_id);
    }

    /**
     * Proof 4: across all three code paths (default definition(),
     * forFirm(), forLead()), firm_id and firmLead->firm_id must never
     * diverge. This is an explicit, combined assertion distinct from
     * proofs 1-3 above — it re-derives each firm_lead independently
     * under its own firm's tenant context and compares, rather than
     * trusting the consultation's own firm_id column in isolation.
     */
    public function test_no_cross_firm_consultation_lead_mismatch_is_ever_created(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $leadB = $this->runWithFirmContext($firmB, fn () => FirmLead::factory()->forFirm($firmB)->create());

        $default = Consultation::factory()->create();
        $viaForFirm = Consultation::factory()->forFirm($firmA)->create();
        $viaForLead = $this->runWithFirmContext($firmB, fn () => Consultation::factory()->forLead($leadB)->create());

        foreach ([$default, $viaForFirm, $viaForLead] as $consultation) {
            $leadFirmId = $this->runWithFirmContext(
                $consultation->firm_id,
                fn () => FirmLead::withoutGlobalScopes()->find($consultation->firm_lead_id)
            )->firm_id;

            $this->assertSame(
                $consultation->firm_id,
                $leadFirmId,
                "Consultation #{$consultation->id} must never have firm_id diverge from its firmLead->firm_id."
            );
        }
    }
}
