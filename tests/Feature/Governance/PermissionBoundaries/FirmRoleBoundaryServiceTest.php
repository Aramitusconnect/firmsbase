<?php

namespace Tests\Feature\Governance\PermissionBoundaries;

use App\Enums\FirmUserRole;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterAssignment;
use App\Services\MatterAccessPolicyService;
use App\Services\TrustAccessPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FirmRoleBoundaryServiceTest — regression coverage proving firm-role
 * boundaries are enforced by the ACTUAL backend services
 * (MatterAccessPolicyService, TrustAccessPolicyService), independent
 * of any UI. No service under test is modified by Section 27.
 */
class FirmRoleBoundaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_matter_access_policy_denies_cross_firm_access(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $ownerOfA = FirmUser::factory()->forFirm($firmA)->role(FirmUserRole::FirmOwner)->create();
        $matterInB = Matter::factory()->forFirm($firmB)->create();

        $allowed = app(MatterAccessPolicyService::class)->canAccessMatter($ownerOfA->user, $matterInB);

        $this->assertFalse($allowed);
    }

    public function test_matter_access_policy_denies_unauthorized_roles_without_an_assignment(): void
    {
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firm)->create();

        foreach ([FirmUserRole::Paralegal, FirmUserRole::LegalAssistant, FirmUserRole::Receptionist, FirmUserRole::BillingStaff] as $role) {
            $firmUser = FirmUser::factory()->forFirm($firm)->role($role)->create();

            $allowed = app(MatterAccessPolicyService::class)->canAccessMatter($firmUser->user, $matter);

            $this->assertFalse($allowed, "{$role->value} without an assignment must not access the matter");
        }
    }

    public function test_matter_access_policy_allows_staff_roles_with_an_active_assignment(): void
    {
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firm)->create();
        $paralegal = FirmUser::factory()->forFirm($firm)->role(FirmUserRole::Paralegal)->create();

        MatterAssignment::factory()->forMatter($matter)->forUser($paralegal->user)->create();

        $allowed = app(MatterAccessPolicyService::class)->canAccessMatter($paralegal->user, $matter);

        $this->assertTrue($allowed);
    }

    public function test_trust_access_policy_denies_receptionist_approval(): void
    {
        $service = app(TrustAccessPolicyService::class);

        $this->assertFalse($service->canApprove(FirmUserRole::Receptionist));
    }

    public function test_trust_access_policy_denies_billing_staff_approval(): void
    {
        $service = app(TrustAccessPolicyService::class);

        $this->assertFalse($service->canApprove(FirmUserRole::BillingStaff));
        // BillingStaff may still REQUEST, just never approve.
        $this->assertTrue($service->canRequest(FirmUserRole::BillingStaff));
    }

    public function test_trust_access_policy_denies_paralegal_and_legal_assistant_approval(): void
    {
        $service = app(TrustAccessPolicyService::class);

        $this->assertFalse($service->canApprove(FirmUserRole::Paralegal));
        $this->assertFalse($service->canApprove(FirmUserRole::LegalAssistant));
    }

    public function test_trust_access_policy_allows_firm_owner_and_attorney_approval(): void
    {
        $service = app(TrustAccessPolicyService::class);

        $this->assertTrue($service->canApprove(FirmUserRole::FirmOwner));
        $this->assertTrue($service->canApprove(FirmUserRole::Attorney));
    }

    public function test_trust_access_policy_assert_can_approve_throws_for_a_denied_role(): void
    {
        $firmUser = FirmUser::factory()->role(FirmUserRole::Receptionist)->create();

        $this->expectException(\RuntimeException::class);
        app(TrustAccessPolicyService::class)->assertCanApprove($firmUser);
    }

    public function test_backend_services_enforce_independently_of_any_ui(): void
    {
        // There is no UI anywhere in this repo (no Filament/Livewire/
        // non-default Blade). These assertions call the policy
        // services directly, proving the boundary is enforced at the
        // backend layer, not by hiding a button.
        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));

        $firmUser = FirmUser::factory()->role(FirmUserRole::Receptionist)->create();
        $this->assertFalse(app(TrustAccessPolicyService::class)->canApprove($firmUser->role));
    }
}
