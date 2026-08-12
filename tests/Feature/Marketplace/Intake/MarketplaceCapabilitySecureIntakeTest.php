<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Intake;

use App\Marketplace\Enums\DirectoryFirmProfileLevel;
use App\Marketplace\Enums\MarketplaceCapability;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceCapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 3 — direct
 * proof of MarketplaceCapabilityService's SecureIntake wiring,
 * independent of MarketplaceIntakeEligibilityService's other checks
 * (publication state, accepting_inquiries).
 */
class MarketplaceCapabilitySecureIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_listing_never_has_secure_intake(): void
    {
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create();

        $this->assertFalse((new MarketplaceCapabilityService)->has($directoryFirm, MarketplaceCapability::SecureIntake));
    }

    public function test_claimed_profile_does_not_have_secure_intake(): void
    {
        $directoryFirm = DirectoryFirm::factory()->claimed()->create();

        $this->assertFalse((new MarketplaceCapabilityService)->has($directoryFirm, MarketplaceCapability::SecureIntake));
    }

    public function test_verified_member_has_secure_intake(): void
    {
        $directoryFirm = DirectoryFirm::factory()->member()->create();

        $this->assertTrue((new MarketplaceCapabilityService)->has($directoryFirm, MarketplaceCapability::SecureIntake));
    }

    public function test_profile_level_granting_secure_intake_is_verified_member(): void
    {
        $level = (new MarketplaceCapabilityService)->profileLevelGrantingCapability(MarketplaceCapability::SecureIntake);

        $this->assertSame(DirectoryFirmProfileLevel::VerifiedMember, $level);
    }
}
