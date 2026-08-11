<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Claims;

use App\Enums\FirmUserRole;
use App\Filament\Firm\Pages\MyAttorneyClaimPage;
use App\Marketplace\Enums\ClaimState;
use App\Marketplace\Enums\DirectoryFirmProfileLevel;
use App\Marketplace\Models\DirectoryClaim;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceClaimAccessPolicyService;
use App\Marketplace\Services\MarketplaceClaimService;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ClaimWorkflowTest — Mission 2 (MyAttorney Marketplace Core),
 * checkpoint 6. Covers the claim test matrix (section 85, items L-U)
 * and the security items directly relevant to this checkpoint (section
 * 88 BF/BG/BH-equivalent: cross-firm isolation, CSRF/session boundary,
 * duplicate/conflict handling).
 */
final class ClaimWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private MarketplaceClaimService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MarketplaceClaimService::class);
        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // Initiation.
    // ------------------------------------------------------------

    public function test_initiating_a_claim_creates_a_pending_claim_attributed_to_the_claimants_own_firm(): void
    {
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create();
        [$firm, $claimant] = $this->firmOwner();

        $claim = $this->service->initiate($directoryFirm, $claimant, 'I am the managing partner.');

        $this->assertSame(ClaimState::Pending, $claim->state);
        $this->assertSame($directoryFirm->id, $claim->directory_firm_id);
        $this->assertSame($firm->id, $claim->firm_id);
        $this->assertSame($claimant->id, $claim->claimant_firm_user_id);
        $this->assertSame('I am the managing partner.', $claim->claim_basis);
        $this->assertNotNull($claim->submitted_at);
        $this->assertNotNull($claim->expires_at);
    }

    public function test_initiating_a_claim_on_an_already_claimed_listing_is_rejected(): void
    {
        $directoryFirm = DirectoryFirm::factory()->claimed()->create();
        [, $claimant] = $this->firmOwner();

        $this->expectException(\RuntimeException::class);
        $this->service->initiate($directoryFirm, $claimant);
    }

    public function test_a_firm_cannot_submit_a_second_active_claim_on_the_same_listing(): void
    {
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create();
        [, $claimant] = $this->firmOwner();

        $this->service->initiate($directoryFirm, $claimant);

        $this->expectException(\RuntimeException::class);
        $this->service->initiate($directoryFirm, $claimant);
    }

    public function test_a_conflicting_claim_from_a_different_firm_is_created_disputed_and_linked(): void
    {
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create();
        [, $firstClaimant] = $this->firmOwner();
        [, $secondClaimant] = $this->firmOwner();

        $firstClaim = $this->service->initiate($directoryFirm, $firstClaimant);
        $secondClaim = $this->service->initiate($directoryFirm, $secondClaimant);

        $this->assertSame(ClaimState::Pending, $firstClaim->state);
        $this->assertSame(ClaimState::Disputed, $secondClaim->state);
        $this->assertSame($firstClaim->id, $secondClaim->conflicts_with_claim_id);
    }

    public function test_initiating_a_claim_records_a_firm_user_audit_event(): void
    {
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create();
        [$firm, $claimant] = $this->firmOwner();

        $claim = $this->service->initiate($directoryFirm, $claimant);

        $event = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('event_type', 'marketplace_claim_initiated')
            ->where('firm_id', $firm->id)
            ->first());

        $this->assertNotNull($event, 'Initiating a claim must record a security_events row.');
        $this->assertSame($claim->id, $event->metadata['directory_claim_id']);
    }

    // ------------------------------------------------------------
    // Approve / reject / revoke (admin lifecycle).
    // ------------------------------------------------------------

    public function test_approving_a_claim_links_the_directory_firm_and_records_an_admin_audit_event(): void
    {
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create();
        [$firm, $claimant] = $this->firmOwner();
        $claim = $this->service->initiate($directoryFirm, $claimant);
        $reviewer = PlatformAdmin::factory()->create();

        $approved = $this->service->approve($claim, $reviewer);

        $this->assertSame(ClaimState::Approved, $approved->state);
        $this->assertNotNull($approved->decided_at);
        $this->assertSame($reviewer->id, $approved->decided_by_platform_admin_id);

        $freshDirectoryFirm = $directoryFirm->fresh();
        $this->assertTrue($freshDirectoryFirm->is_claimed);
        $this->assertNotNull($freshDirectoryFirm->claimed_at);
        $this->assertSame($firm->id, $freshDirectoryFirm->firm_id);
        $this->assertSame(DirectoryFirmProfileLevel::ClaimedProfile, $freshDirectoryFirm->profileLevel());

        $event = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('event_type', 'marketplace_claim_approved')
            ->where('firm_id', $firm->id)
            ->first());
        $this->assertNotNull($event, 'Approving a claim must record a security_events row.');
    }

    public function test_approving_one_claim_auto_rejects_every_other_active_claim_on_the_same_listing_with_a_preserved_reason(): void
    {
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create();
        [, $firstClaimant] = $this->firmOwner();
        [, $secondClaimant] = $this->firmOwner();
        $firstClaim = $this->service->initiate($directoryFirm, $firstClaimant);
        $secondClaim = $this->service->initiate($directoryFirm, $secondClaimant); // Disputed — conflicts with firstClaim.
        $reviewer = PlatformAdmin::factory()->create();

        $this->service->approve($firstClaim, $reviewer);

        $freshSecond = $secondClaim->fresh();
        $this->assertSame(ClaimState::Rejected, $freshSecond->state);
        $this->assertNotNull($freshSecond->rejection_reason);
        $this->assertNotNull($freshSecond->decided_at, 'A rejected claim must preserve its own decision timestamp, not be silently deleted.');
    }

    public function test_approving_an_already_decided_claim_is_rejected(): void
    {
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create();
        [, $claimant] = $this->firmOwner();
        $claim = $this->service->initiate($directoryFirm, $claimant);
        $reviewer = PlatformAdmin::factory()->create();
        $this->service->approve($claim, $reviewer);

        $this->expectException(\RuntimeException::class);
        $this->service->approve($claim->fresh(), $reviewer);
    }

    public function test_rejecting_a_claim_never_touches_the_directory_firm(): void
    {
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create();
        [, $claimant] = $this->firmOwner();
        $claim = $this->service->initiate($directoryFirm, $claimant);
        $reviewer = PlatformAdmin::factory()->create();

        $rejected = $this->service->reject($claim, $reviewer, 'Not enough evidence.');

        $this->assertSame(ClaimState::Rejected, $rejected->state);
        $this->assertSame('Not enough evidence.', $rejected->rejection_reason);

        $freshDirectoryFirm = $directoryFirm->fresh();
        $this->assertFalse($freshDirectoryFirm->is_claimed);
        $this->assertNull($freshDirectoryFirm->firm_id);
    }

    public function test_revoking_an_approved_claim_unclaims_the_directory_firm(): void
    {
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create();
        [$firm, $claimant] = $this->firmOwner();
        $claim = $this->service->initiate($directoryFirm, $claimant);
        $reviewer = PlatformAdmin::factory()->create();
        $this->service->approve($claim, $reviewer);

        $revoked = $this->service->revoke($claim->fresh(), $reviewer, 'Authority could not be re-verified.');

        $this->assertSame(ClaimState::Revoked, $revoked->state);
        $this->assertNotNull($revoked->revoked_at);
        $this->assertSame('Authority could not be re-verified.', $revoked->revocation_reason);

        $freshDirectoryFirm = $directoryFirm->fresh();
        $this->assertFalse($freshDirectoryFirm->is_claimed);
        $this->assertNull($freshDirectoryFirm->claimed_at);
        $this->assertNull($freshDirectoryFirm->firm_id);
        $this->assertSame(DirectoryFirmProfileLevel::PublicListing, $freshDirectoryFirm->profileLevel());

        unset($firm);
    }

    public function test_revoking_a_non_approved_claim_is_rejected(): void
    {
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create();
        [, $claimant] = $this->firmOwner();
        $claim = $this->service->initiate($directoryFirm, $claimant);
        $reviewer = PlatformAdmin::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->service->revoke($claim, $reviewer, 'n/a');
    }

    public function test_expire_stale_claims_transitions_only_past_due_active_claims(): void
    {
        $directoryFirmA = DirectoryFirm::factory()->unclaimed()->create();
        $directoryFirmB = DirectoryFirm::factory()->unclaimed()->create();
        [$firmA, $claimantA] = $this->firmOwner();
        [$firmB, $claimantB] = $this->firmOwner();

        $staleClaim = $this->service->initiate($directoryFirmA, $claimantA);
        $staleClaim->update(['expires_at' => now()->subDay()]);

        $freshClaim = $this->service->initiate($directoryFirmB, $claimantB);

        $expiredCount = $this->service->expireStaleClaims();

        $this->assertSame(1, $expiredCount);
        $this->assertSame(ClaimState::Expired, $staleClaim->fresh()->state);
        $this->assertSame(ClaimState::Pending, $freshClaim->fresh()->state);
    }

    // ------------------------------------------------------------
    // Access policy — ownership never inferred from a client value.
    // ------------------------------------------------------------

    public function test_access_policy_owns_claim_compares_the_claims_real_firm_id_to_the_firm_users_own(): void
    {
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create();
        [$firmA, $claimantA] = $this->firmOwner();
        [, $claimantB] = $this->firmOwner();
        $claim = $this->service->initiate($directoryFirm, $claimantA);

        $policy = app(MarketplaceClaimAccessPolicyService::class);

        $this->assertTrue($policy->ownsClaim($claimantA, $claim));
        $this->assertFalse($policy->ownsClaim($claimantB, $claim));

        unset($firmA);
    }

    public function test_access_policy_can_manage_claims_is_firm_owner_only(): void
    {
        $policy = app(MarketplaceClaimAccessPolicyService::class);

        $this->assertTrue($policy->canManageClaims(FirmUserRole::FirmOwner));
        foreach (FirmUserRole::cases() as $role) {
            if ($role === FirmUserRole::FirmOwner) {
                continue;
            }
            $this->assertFalse($policy->canManageClaims($role), "canManageClaims() must be false for {$role->value}.");
        }
    }

    // ------------------------------------------------------------
    // Firm-panel claim initiation page (HTTP/Livewire).
    // ------------------------------------------------------------

    public function test_guest_is_redirected_away_from_the_claim_page(): void
    {
        $response = $this->get($this->firmAppUrl('/myattorney-claim'));

        $response->assertRedirect();
    }

    public function test_firm_owner_can_view_the_claim_page_for_an_unclaimed_listing(): void
    {
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create(['display_name' => 'Acme Legal Group']);
        [$firm] = $this->actingAsRole(FirmUserRole::FirmOwner);

        $response = $this->get($this->firmAppUrl('/myattorney-claim?firm='.$directoryFirm->slug));

        $response->assertOk();
        $response->assertSee('Acme Legal Group');
        $response->assertSee('Submit Claim');

        unset($firm);
    }

    public function test_non_owner_role_does_not_see_the_submit_claim_action(): void
    {
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create();
        $this->actingAsRole(FirmUserRole::Attorney);

        $response = $this->get($this->firmAppUrl('/myattorney-claim?firm='.$directoryFirm->slug));

        $response->assertOk();
        $response->assertDontSee('Submit Claim');
    }

    public function test_firm_owner_submitting_the_claim_form_creates_a_claim_attributed_to_their_own_firm_only(): void
    {
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create();
        [$firm, $firmUser] = $this->actingAsRole(FirmUserRole::FirmOwner);

        // mount() reads request()->query('firm') directly (not a
        // Livewire #[Url] property) — Livewire::withQueryParams() is
        // the supported way to inject a query string into the request
        // a Livewire::test() component boots against.
        Livewire::withQueryParams(['firm' => $directoryFirm->slug]);

        Livewire::test(MyAttorneyClaimPage::class)
            ->set('data.claim_basis', 'I am the firm owner.')
            ->call('submitClaim');

        $claim = DirectoryClaim::query()->where('directory_firm_id', $directoryFirm->id)->first();
        $this->assertNotNull($claim);
        // The claimant firm_id is the acting FirmUser's own tenant firm
        // — there is no form field for it at all, so it can never be
        // spoofed via submitted request data (section 59).
        $this->assertSame($firm->id, $claim->firm_id);
        $this->assertSame($firmUser->id, $claim->claimant_firm_user_id);
    }

    public function test_non_owner_forcing_submit_claim_directly_is_blocked_with_a_403(): void
    {
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create();
        $this->actingAsRole(FirmUserRole::Attorney);

        $this->get($this->firmAppUrl('/myattorney-claim?firm='.$directoryFirm->slug));

        Livewire::test(MyAttorneyClaimPage::class)
            ->call('submitClaim')
            ->assertForbidden();

        $this->assertSame(0, DirectoryClaim::query()->where('directory_firm_id', $directoryFirm->id)->count());
    }

    public function test_a_firm_user_only_ever_sees_their_own_firms_claims_on_the_claim_page(): void
    {
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create();
        [$otherFirm, $otherClaimant] = $this->firmOwner();
        $this->service->initiate($directoryFirm, $otherClaimant);

        $this->actingAsRole(FirmUserRole::FirmOwner);

        $response = $this->get($this->firmAppUrl('/myattorney-claim?firm='.$directoryFirm->slug));

        // The page must still offer to claim (own-firm view has no
        // active claim), even though ANOTHER firm's claim exists on
        // this same listing — its state is never visible here.
        $response->assertSee('Submit Claim');

        unset($otherFirm);
    }

    // ------------------------------------------------------------
    // The public marketplace host never carries the claim mutation
    // surface — only a plain link to the authenticated Firm app.
    // ------------------------------------------------------------

    public function test_the_public_firm_profile_claim_link_points_to_the_firm_app_host_not_myattorney(): void
    {
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create(['display_name' => 'Unclaimed Firm']);

        $response = $this->get($this->myAttorneyUrl('/firms/'.$directoryFirm->slug));

        $response->assertOk();
        $response->assertSee('Claim This Listing');
        $response->assertSee($this->firmAppUrl('/myattorney-claim'), false);
    }

    public function test_the_public_firm_profile_never_shows_a_claim_link_once_already_claimed(): void
    {
        $directoryFirm = DirectoryFirm::factory()->claimed()->create();

        $response = $this->get($this->myAttorneyUrl('/firms/'.$directoryFirm->slug));

        $response->assertOk();
        $response->assertDontSee('Claim This Listing');
    }

    // ------------------------------------------------------------
    // RLS exemption — directory_claims is genuinely Global, not
    // firm-scoped RLS, matching directory_firms' own precedent.
    // ------------------------------------------------------------

    public function test_directory_claims_table_is_genuinely_exempt_from_row_level_security(): void
    {
        $row = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', ['directory_claims']);

        $this->assertNotNull($row, 'directory_claims not found in pg_class.');
        $this->assertFalse((bool) $row->relrowsecurity, 'RLS must NOT be enabled on directory_claims — it is platform-global data.');
        $this->assertFalse((bool) $row->relforcerowsecurity, 'FORCE RLS must NOT be enabled on directory_claims.');
    }

    public function test_directory_claims_unique_pair_constraint_is_not_relied_on_duplicate_prevention_is_app_level(): void
    {
        // No DB-level unique constraint on (directory_firm_id, firm_id)
        // exists — duplicate-active-claim prevention is deliberately
        // app-level (MarketplaceClaimService), since a legitimate
        // history can contain multiple TERMINAL claims (e.g. rejected,
        // then a later successful one) from the same firm on the same
        // listing. This test documents that a raw duplicate insert at
        // the DB layer is NOT itself rejected — confirming the
        // service's transactional row-lock guard is the real
        // enforcement point, not an incidental DB constraint.
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create();
        [$firm, $claimant] = $this->firmOwner();

        DirectoryClaim::factory()->forDirectoryFirmAndClaimant($directoryFirm, $claimant)->rejected()->create();

        try {
            DirectoryClaim::factory()->forDirectoryFirmAndClaimant($directoryFirm, $claimant)->rejected()->create();
            $this->assertTrue(true);
        } catch (QueryException $e) {
            $this->fail('Two terminal-state claim rows from the same firm on the same listing must not be blocked at the DB layer: '.$e->getMessage());
        }

        unset($firm);
    }

    // ------------------------------------------------------------
    // Helpers.
    // ------------------------------------------------------------

    /**
     * @return array{0: Firm, 1: FirmUser}
     */
    private function firmOwner(): array
    {
        $firm = Firm::factory()->create();
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create()
        );

        return [$firm, $firmUser];
    }

    /**
     * @return array{0: Firm, 1: FirmUser}
     */
    private function actingAsRole(FirmUserRole $role): array
    {
        $firm = Firm::factory()->create();
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return [$firm, $firmUser];
    }
}
