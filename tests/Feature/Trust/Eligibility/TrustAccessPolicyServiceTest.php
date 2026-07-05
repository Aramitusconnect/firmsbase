<?php

namespace Tests\Feature\Trust\Eligibility;

use App\Enums\FirmUserRole;
use App\Models\FirmUser;
use App\Services\TrustAccessPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correction #6, direct unit coverage: FirmOwner/Attorney/BillingStaff
 * may request; only FirmOwner/Attorney may approve; two DIFFERENT
 * approvers (both eligible) are required for assertDistinctApprovers().
 */
class TrustAccessPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private TrustAccessPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TrustAccessPolicyService::class);
    }

    public function test_firm_owner_attorney_and_billing_staff_can_request(): void
    {
        foreach ([FirmUserRole::FirmOwner, FirmUserRole::Attorney, FirmUserRole::BillingStaff] as $role) {
            $this->assertTrue($this->service->canRequest($role));
        }
    }

    public function test_paralegal_legal_assistant_and_receptionist_cannot_request(): void
    {
        foreach ([FirmUserRole::Paralegal, FirmUserRole::LegalAssistant, FirmUserRole::Receptionist] as $role) {
            $this->assertFalse($this->service->canRequest($role));
        }
    }

    public function test_only_firm_owner_and_attorney_can_approve(): void
    {
        $this->assertTrue($this->service->canApprove(FirmUserRole::FirmOwner));
        $this->assertTrue($this->service->canApprove(FirmUserRole::Attorney));
        $this->assertFalse($this->service->canApprove(FirmUserRole::BillingStaff));
    }

    public function test_assert_distinct_approvers_requires_two_different_eligible_users(): void
    {
        $firstApprover = FirmUser::factory()->role(FirmUserRole::FirmOwner)->create();
        $secondApprover = FirmUser::factory()->role(FirmUserRole::Attorney)->create();

        $this->service->assertDistinctApprovers($firstApprover, $secondApprover);
        $this->assertTrue(true);
    }

    public function test_assert_distinct_approvers_rejects_the_same_user_twice(): void
    {
        $approver = FirmUser::factory()->role(FirmUserRole::FirmOwner)->create();

        $this->expectException(\RuntimeException::class);
        $this->service->assertDistinctApprovers($approver, $approver);
    }
}
