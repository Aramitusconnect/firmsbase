<?php

namespace Tests\Feature\Exports;

use App\Models\Firm;
use App\Models\FirmLicense;
use App\Services\ExportGovernancePolicyService;
use App\Services\LegalDataAccessPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportGovernancePolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExportGovernancePolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExportGovernancePolicyService(new LegalDataAccessPolicyService());
    }

    public function test_export_is_allowed_for_a_firm_in_good_standing(): void
    {
        $firm = Firm::factory()->create();
        FirmLicense::factory()->create(['firm_id' => $firm->id, 'license_status' => \App\Enums\LicenseStatus::Active->value]);

        $decision = $this->service->evaluate($firm->fresh());

        $this->assertTrue($decision->allowed);
    }

    public function test_export_governance_blocks_when_a_legal_hold_is_active(): void
    {
        $firm = Firm::factory()->create();
        FirmLicense::factory()->create(['firm_id' => $firm->id, 'license_status' => \App\Enums\LicenseStatus::Active->value]);

        $decision = $this->service->evaluate($firm->fresh(), hasActiveLegalHold: true);

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('legal hold', $decision->reason);
    }

    public function test_export_governance_blocks_when_retention_period_has_expired(): void
    {
        $firm = Firm::factory()->create();
        FirmLicense::factory()->create(['firm_id' => $firm->id, 'license_status' => \App\Enums\LicenseStatus::Active->value]);

        $decision = $this->service->evaluate($firm->fresh(), retentionPeriodExpired: true);

        $this->assertFalse($decision->allowed);
    }

    public function test_offboarding_firm_can_still_export_its_own_data(): void
    {
        $firm = Firm::factory()->create();
        FirmLicense::factory()->create(['firm_id' => $firm->id, 'license_status' => \App\Enums\LicenseStatus::Active->value]);

        $decision = $this->service->evaluate($firm->fresh(), firmIsOffboarding: true);

        $this->assertTrue($decision->allowed);
    }

    public function test_export_governance_defers_to_legal_data_access_policy_for_suspended_firms(): void
    {
        $firm = Firm::factory()->create();
        FirmLicense::factory()->create(['firm_id' => $firm->id, 'license_status' => \App\Enums\LicenseStatus::Cancelled->value]);

        // Cancelled is still export-only under LegalDataAccessPolicyService,
        // so export should remain allowed even though interactive read is not.
        $decision = $this->service->evaluate($firm->fresh());

        $this->assertTrue($decision->allowed);
    }
}
