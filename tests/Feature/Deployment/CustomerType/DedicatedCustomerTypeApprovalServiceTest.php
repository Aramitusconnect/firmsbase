<?php

namespace Tests\Feature\Deployment\CustomerType;

use App\Enums\CustomerType;
use App\Enums\DeploymentMode;
use App\Services\DedicatedCustomerTypeApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Deployment\Concerns\SetsUpDeploymentFirm;
use Tests\TestCase;

/**
 * Project rule 17: dedicated + legal_specialist requires an approved
 * two-person high-risk request; dedicated + law_firm needs no gate.
 */
class DedicatedCustomerTypeApprovalServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpDeploymentFirm;

    private DedicatedCustomerTypeApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DedicatedCustomerTypeApprovalService::class);
    }

    public function test_dedicated_law_firm_requires_no_approval_gate(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::Dedicated, CustomerType::LawFirm);

        $this->assertFalse($this->service->isApproved($firm));
        // No gate means the ABSENCE of approval must never itself block
        // a law_firm — that assertion belongs to whichever later phase
        // enforces access; this test only proves isApproved() correctly
        // reports "no approval request exists" without error.
    }

    public function test_dedicated_legal_specialist_is_blocked_until_approved(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::Dedicated, CustomerType::LegalSpecialist);
        $admin1 = $this->makePlatformAdmin();
        $admin2 = $this->makePlatformAdmin();

        $this->assertFalse($this->service->isApproved($firm));

        $request = $this->service->requestApproval($firm, $admin1, 'Onboarding dedicated legal_specialist customer.');
        $this->assertFalse($this->service->isApproved($firm));

        $this->service->firstApprove($request, $admin1);
        $this->assertFalse($this->service->isApproved($firm));

        $this->service->secondApprove($request, $admin2);
        $this->assertTrue($this->service->isApproved($firm));
    }

    public function test_approval_for_one_firm_does_not_leak_to_another_firm(): void
    {
        $firmA = $this->makeDeploymentFirm(DeploymentMode::Dedicated, CustomerType::LegalSpecialist);
        $firmB = $this->makeDeploymentFirm(DeploymentMode::Dedicated, CustomerType::LegalSpecialist);
        $admin1 = $this->makePlatformAdmin();
        $admin2 = $this->makePlatformAdmin();

        $request = $this->service->requestApproval($firmA, $admin1, 'Firm A only.');
        $this->service->firstApprove($request, $admin1);
        $this->service->secondApprove($request, $admin2);

        $this->assertTrue($this->service->isApproved($firmA));
        $this->assertFalse($this->service->isApproved($firmB));
    }
}
