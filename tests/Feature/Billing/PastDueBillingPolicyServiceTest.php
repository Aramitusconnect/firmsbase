<?php

namespace Tests\Feature\Billing;

use App\Enums\LicenseStatus;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Services\LegalDataAccessPolicyService;
use App\Services\PastDueBillingPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirms PastDueBillingPolicyService is a thin delegate to the
 * EXISTING Phase 5 LegalDataAccessPolicyService — no read/write/export
 * decision is reimplemented here, and past-due/suspended firms are
 * never abruptly locked out of legal data (project rule 10).
 */
class PastDueBillingPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private PastDueBillingPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PastDueBillingPolicyService(new LegalDataAccessPolicyService());
    }

    public function test_past_due_firm_retains_read_access_but_loses_write(): void
    {
        $firm = Firm::factory()->create();
        FirmLicense::factory()->create(['firm_id' => $firm->id, 'license_status' => LicenseStatus::PastDue]);

        $this->assertTrue($this->service->canRead($firm));
        $this->assertFalse($this->service->canWrite($firm));
    }

    public function test_suspended_firm_retains_export_access_never_destroyed_or_hidden(): void
    {
        $firm = Firm::factory()->create();
        FirmLicense::factory()->create(['firm_id' => $firm->id, 'license_status' => LicenseStatus::Suspended]);

        $this->assertFalse($this->service->canRead($firm));
        $this->assertTrue($this->service->canExport($firm));
    }

    public function test_active_firm_has_full_access(): void
    {
        $firm = Firm::factory()->create();
        FirmLicense::factory()->create(['firm_id' => $firm->id, 'license_status' => LicenseStatus::Active]);

        $this->assertTrue($this->service->canRead($firm));
        $this->assertTrue($this->service->canWrite($firm));
        $this->assertTrue($this->service->canExport($firm));
    }

    public function test_summary_reports_the_current_status_and_all_three_access_flags(): void
    {
        $firm = Firm::factory()->create();
        FirmLicense::factory()->create(['firm_id' => $firm->id, 'license_status' => LicenseStatus::Restricted]);

        $summary = $this->service->summary($firm);

        $this->assertSame('restricted', $summary['status']);
        $this->assertTrue($summary['can_read']);
        $this->assertFalse($summary['can_write']);
        $this->assertTrue($summary['can_export']);
    }
}
