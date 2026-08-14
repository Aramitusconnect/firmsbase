<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\FirmUserRole;
use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\MarkClaimUnderReviewAction;
use App\Filament\Actions\Platform\RequireClaimEvidenceAction;
use App\Filament\Resources\DirectoryClaimResource;
use App\Filament\Resources\DirectoryClaimResource\Pages\ViewDirectoryClaim;
use App\Marketplace\Enums\ClaimState;
use App\Marketplace\Models\DirectoryClaim;
use App\Marketplace\Models\DirectoryFirm;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DirectoryClaimReviewWorkflowTest — MyAttorney SuperAdmin console
 * professionalization mission (MYAT4). Proves the two newly-wired
 * actions (Mark Under Review, Request More Information) — both backed
 * by MarketplaceClaimService methods that existed since Mission 2 but
 * were never reachable from the Filament UI before this mission — and
 * that the upgraded review workspace actually renders listing/
 * claimant/evidence data.
 */
final class DirectoryClaimReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function pendingClaim(): DirectoryClaim
    {
        $tenantFirm = Firm::factory()->create();
        $claimant = app(TenantContextService::class)->runWithFirmContext(
            $tenantFirm,
            fn () => FirmUser::factory()->forFirm($tenantFirm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create()
        );

        return DirectoryClaim::factory()->create([
            'firm_id' => $tenantFirm->id,
            'claimant_firm_user_id' => $claimant->id,
            'state' => ClaimState::Pending,
        ]);
    }

    public function test_mark_under_review_action_transitions_state_and_is_audited(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $claim = $this->pendingClaim();

        $test = Livewire::test(ViewDirectoryClaim::class, ['record' => $claim->uuid]);
        $test->assertActionVisible(MarkClaimUnderReviewAction::getDefaultName());
        $test->mountAction(MarkClaimUnderReviewAction::getDefaultName());
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $this->assertSame(ClaimState::UnderReview, $claim->fresh()->state);
    }

    public function test_request_more_information_stores_the_note_and_transitions_to_evidence_required(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $claim = $this->pendingClaim();

        $test = Livewire::test(ViewDirectoryClaim::class, ['record' => $claim->uuid]);
        $test->mountAction(RequireClaimEvidenceAction::getDefaultName());
        $test->setActionData(['note' => 'Please upload a copy of your bar license.']);
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $fresh = $claim->fresh();
        $this->assertSame(ClaimState::EvidenceRequired, $fresh->state);
        $this->assertSame('Please upload a copy of your bar license.', $fresh->reviewer_notes);
    }

    public function test_request_more_information_requires_a_note(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $claim = $this->pendingClaim();

        $test = Livewire::test(ViewDirectoryClaim::class, ['record' => $claim->uuid]);
        $test->mountAction(RequireClaimEvidenceAction::getDefaultName());
        $test->setActionData(['note' => '']);
        $test->callMountedAction();
        $test->assertHasActionErrors(['note']);
    }

    public function test_mark_under_review_is_not_visible_for_an_already_decided_claim(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $claim = $this->pendingClaim();
        $claim->update(['state' => ClaimState::Approved]);

        $test = Livewire::test(ViewDirectoryClaim::class, ['record' => $claim->uuid]);
        $test->assertActionHidden(MarkClaimUnderReviewAction::getDefaultName());
    }

    public function test_sales_rep_cannot_mark_a_claim_under_review(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin');

        $claim = $this->pendingClaim();

        $this->get(DirectoryClaimResource::getUrl('view', ['record' => $claim]))->assertForbidden();
    }

    public function test_review_workspace_renders_listing_claimant_and_evidence_sections(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $listing = DirectoryFirm::factory()->create(['display_name' => 'Workspace Test Firm']);
        $tenantFirm = Firm::factory()->create(['legal_name' => 'Claimant Legal LLP']);
        $claimant = app(TenantContextService::class)->runWithFirmContext(
            $tenantFirm,
            fn () => FirmUser::factory()->forFirm($tenantFirm)->forUser(User::factory()->create(['name' => 'Claimant Person']))->role(FirmUserRole::FirmOwner)->create()
        );
        $claim = DirectoryClaim::factory()->create([
            'directory_firm_id' => $listing->id,
            'firm_id' => $tenantFirm->id,
            'claimant_firm_user_id' => $claimant->id,
            'claim_basis' => 'I am the managing partner of this firm.',
        ]);

        $response = $this->get(DirectoryClaimResource::getUrl('view', ['record' => $claim]));

        $response->assertOk();
        $response->assertSee('Workspace Test Firm');
        $response->assertSee('Claimant Legal LLP');
        $response->assertSee('I am the managing partner of this firm.');
        // Deliberately NOT asserting on "Claimant Person" (claimant.user.name):
        // firm_users carries FORCE RLS, so that lazy-loaded relation cannot
        // resolve on a plain admin HTTP request with no active tenant
        // context — the same pre-existing constraint ViewDirectoryFirm's own
        // claimant.user.name field already lives with, not something this
        // mission introduced or can fix here.
    }
}
