<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Directory;

use App\Marketplace\Enums\DirectoryFirmProfileLevel;
use App\Marketplace\Enums\MarketplaceBadge;
use App\Marketplace\Enums\MarketplaceCapability;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceBadgeService;
use App\Marketplace\Services\MarketplaceCapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CapabilityAndBadgeModelTest — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 3. Proves the capability model (section 67) and
 * badge system (section 19): an unclaimed Public Listing has zero
 * privileged capabilities, capabilities/badges scale correctly with
 * profile level, reserved/future vocabulary is never actually
 * granted, and no vague/unsupported badge can ever be produced.
 */
class CapabilityAndBadgeModelTest extends TestCase
{
    use RefreshDatabase;

    private MarketplaceCapabilityService $capabilities;

    private MarketplaceBadgeService $badges;

    protected function setUp(): void
    {
        parent::setUp();
        $this->capabilities = app(MarketplaceCapabilityService::class);
        $this->badges = app(MarketplaceBadgeService::class);
    }

    public function test_an_unclaimed_public_listing_has_zero_capabilities(): void
    {
        $firm = DirectoryFirm::factory()->unclaimed()->create();

        $this->assertSame([], $this->capabilities->capabilitiesFor($firm));
    }

    public function test_a_claimed_profile_gains_profile_and_claim_management(): void
    {
        $firm = DirectoryFirm::factory()->claimed()->create();

        $granted = $this->capabilities->capabilitiesFor($firm);

        $this->assertContains(MarketplaceCapability::ProfileManagement, $granted);
        $this->assertContains(MarketplaceCapability::ClaimManagement, $granted);
        $this->assertNotContains(MarketplaceCapability::MemberBadge, $granted);
    }

    public function test_a_verified_member_additionally_gains_the_member_badge_capability(): void
    {
        $firm = DirectoryFirm::factory()->member()->create();

        $granted = $this->capabilities->capabilitiesFor($firm);

        $this->assertContains(MarketplaceCapability::ProfileManagement, $granted);
        $this->assertContains(MarketplaceCapability::ClaimManagement, $granted);
        $this->assertContains(MarketplaceCapability::MemberBadge, $granted);
    }

    /**
     * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 3
     * legitimately activated SecureIntake (granted only at
     * verified_member — see MarketplaceCapabilityService's own
     * docblock) — it is no longer reserved-only, so it moved out of
     * this list into its own assertion below.
     * ConsultationRequests/Scheduling/LeadDelivery remain reserved.
     */
    public function test_still_reserved_mission_3_capabilities_are_never_granted_at_any_profile_level(): void
    {
        $reserved = [
            MarketplaceCapability::ConsultationRequests,
            MarketplaceCapability::Scheduling,
            MarketplaceCapability::LeadDelivery,
        ];

        foreach ([
            DirectoryFirm::factory()->unclaimed()->create(),
            DirectoryFirm::factory()->claimed()->create(),
            DirectoryFirm::factory()->member()->create(),
        ] as $firm) {
            $granted = $this->capabilities->capabilitiesFor($firm);
            foreach ($reserved as $capability) {
                $this->assertNotContains($capability, $granted, "{$capability->value} must still never be granted.");
            }
        }
    }

    public function test_secure_intake_is_granted_only_at_verified_member(): void
    {
        $this->assertNotContains(MarketplaceCapability::SecureIntake, $this->capabilities->capabilitiesFor(DirectoryFirm::factory()->unclaimed()->create()));
        $this->assertNotContains(MarketplaceCapability::SecureIntake, $this->capabilities->capabilitiesFor(DirectoryFirm::factory()->claimed()->create()));
        $this->assertContains(MarketplaceCapability::SecureIntake, $this->capabilities->capabilitiesFor(DirectoryFirm::factory()->member()->create()));
    }

    public function test_has_helper_matches_capabilities_for(): void
    {
        $firm = DirectoryFirm::factory()->claimed()->create();

        $this->assertTrue($this->capabilities->has($firm, MarketplaceCapability::ProfileManagement));
        $this->assertFalse($this->capabilities->has($firm, MarketplaceCapability::MemberBadge));
    }

    public function test_profile_level_granting_capability_resolves_the_minimum_level(): void
    {
        $this->assertSame(
            DirectoryFirmProfileLevel::ClaimedProfile,
            $this->capabilities->profileLevelGrantingCapability(MarketplaceCapability::ProfileManagement)
        );
        $this->assertSame(
            DirectoryFirmProfileLevel::VerifiedMember,
            $this->capabilities->profileLevelGrantingCapability(MarketplaceCapability::MemberBadge)
        );
        // Mission 3, checkpoint 3 activated SecureIntake at verified_member
        // — no longer null (that was Mission 2's reserved-vocabulary state).
        $this->assertSame(
            DirectoryFirmProfileLevel::VerifiedMember,
            $this->capabilities->profileLevelGrantingCapability(MarketplaceCapability::SecureIntake)
        );
        $this->assertNull($this->capabilities->profileLevelGrantingCapability(MarketplaceCapability::ConsultationRequests));
    }

    public function test_an_unclaimed_firm_shows_only_the_public_listing_badge(): void
    {
        $firm = DirectoryFirm::factory()->unclaimed()->create();

        $this->assertSame([MarketplaceBadge::PublicListing], $this->badges->badgesFor($firm));
    }

    public function test_a_claimed_firm_shows_the_claimed_profile_badge_not_public_listing(): void
    {
        $firm = DirectoryFirm::factory()->claimed()->create();

        $badges = $this->badges->badgesFor($firm);

        $this->assertContains(MarketplaceBadge::ClaimedProfile, $badges);
        $this->assertNotContains(MarketplaceBadge::PublicListing, $badges);
    }

    public function test_a_member_firm_additionally_shows_the_firmsvault_member_badge(): void
    {
        $firm = DirectoryFirm::factory()->member()->create();

        $badges = $this->badges->badgesFor($firm);

        $this->assertContains(MarketplaceBadge::ClaimedProfile, $badges);
        $this->assertContains(MarketplaceBadge::FirmsVaultMember, $badges);
    }

    public function test_firm_authority_verified_badge_never_appears_absent_a_real_verification_record(): void
    {
        foreach ([
            DirectoryFirm::factory()->unclaimed()->create(),
            DirectoryFirm::factory()->claimed()->create(),
            DirectoryFirm::factory()->member()->create(),
        ] as $firm) {
            $this->assertNotContains(MarketplaceBadge::FirmAuthorityVerified, $this->badges->badgesFor($firm));
        }
    }

    public function test_attorney_identity_verified_badge_never_appears_absent_a_real_verification_record(): void
    {
        $attorney = DirectoryAttorney::factory()->create();

        $this->assertNotContains(MarketplaceBadge::AttorneyIdentityVerified, $this->badges->badgesForAttorney($attorney));
    }

    public function test_google_connected_badge_is_never_produced_by_either_service(): void
    {
        $firm = DirectoryFirm::factory()->member()->create();
        $attorney = DirectoryAttorney::factory()->create();

        $this->assertNotContains(MarketplaceBadge::GoogleConnected, $this->badges->badgesFor($firm));
        $this->assertNotContains(MarketplaceBadge::GoogleConnected, $this->badges->badgesForAttorney($attorney));
    }

    public function test_no_vague_or_unsupported_badge_language_exists_in_the_enum(): void
    {
        $labels = array_map(fn (MarketplaceBadge $badge) => $badge->label(), MarketplaceBadge::cases());

        foreach (['Verified & Trusted', 'Best Lawyer', 'Recommended Lawyer', 'Top Attorney', 'Guaranteed'] as $forbidden) {
            $this->assertNotContains($forbidden, $labels);
        }
    }
}
