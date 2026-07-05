<?php

namespace Tests\Feature\Trust\Readiness;

use App\Models\Firm;
use App\Models\FirmSettings;
use App\Services\TrustJurisdictionReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correction #16: this service must remain static/checklist-only and
 * must never make a compliance claim.
 */
class TrustJurisdictionReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_checklist_makes_no_compliance_claim(): void
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create(['state_jurisdiction' => 'CA']);

        $checklist = app(TrustJurisdictionReadinessService::class)->checklistFor($firm->fresh());

        $this->assertFalse($checklist['compliance_claim_made']);
        $this->assertSame('CA', $checklist['reference_state_jurisdiction']);
        $this->assertNotEmpty($checklist['review_items']);
    }

    public function test_checklist_is_purely_static_and_does_not_depend_on_any_gating_service(): void
    {
        $firm = Firm::factory()->create();

        // No entitlement, no payment_mode configuration, no trust
        // mode activation exists for this firm at all — the checklist
        // must still be returned, since it gates nothing.
        $checklist = app(TrustJurisdictionReadinessService::class)->checklistFor($firm);

        $this->assertIsArray($checklist['review_items']);
    }
}
