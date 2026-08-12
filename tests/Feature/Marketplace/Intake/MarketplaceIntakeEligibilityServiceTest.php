<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Intake;

use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceIntakeEligibilityService;
use App\Marketplace\ValueObjects\MarketplaceIntakeEligibility;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 3 —
 * MarketplaceIntakeEligibilityService's own ordered check list, each
 * failure mode proven independently.
 */
class MarketplaceIntakeEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketplaceIntakeEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MarketplaceIntakeEligibilityService;
    }

    private function eligibleDirectoryFirm(): DirectoryFirm
    {
        $firm = Firm::factory()->create();

        return DirectoryFirm::factory()->member()->create([
            'firm_id' => $firm->id,
            'accepting_inquiries' => true,
        ]);
    }

    public function test_an_eligible_verified_member_published_accepting_firm_is_eligible(): void
    {
        $directoryFirm = $this->eligibleDirectoryFirm();

        $result = $this->service->evaluate($directoryFirm);

        $this->assertTrue($result->eligible);
        $this->assertNull($result->reasonCode);
    }

    public function test_an_unclaimed_listing_is_denied(): void
    {
        // A real canonical Firm may still be linked (firm_id set) even
        // though nobody has claimed ownership yet — isolates the
        // 'unclaimed' reason from 'no_canonical_firm'.
        $firm = Firm::factory()->create();
        $directoryFirm = DirectoryFirm::factory()->unclaimed()->create(['firm_id' => $firm->id, 'accepting_inquiries' => true]);

        $result = $this->service->evaluate($directoryFirm);

        $this->assertFalse($result->eligible);
        $this->assertSame('unclaimed', $result->reasonCode);
    }

    public function test_a_claimed_but_non_capable_firm_is_denied(): void
    {
        // claimed() alone (not member()) grants ClaimManagement/
        // ProfileManagement but never SecureIntake — a real, distinct
        // "claimed but non-capable" state from plain "unclaimed".
        $firm = Firm::factory()->create();
        $directoryFirm = DirectoryFirm::factory()->claimed()->create([
            'firm_id' => $firm->id,
            'accepting_inquiries' => true,
        ]);

        $result = $this->service->evaluate($directoryFirm);

        $this->assertFalse($result->eligible);
        $this->assertSame('not_capable', $result->reasonCode);
    }

    public function test_a_listing_with_no_canonical_firm_link_is_denied_even_if_claimed(): void
    {
        // Defensive proof: is_claimed=true with firm_id still null must
        // never be treated as eligible — the canonical-Firm-resolution
        // check runs first and independently of is_claimed.
        $directoryFirm = DirectoryFirm::factory()->member()->create(['firm_id' => null, 'accepting_inquiries' => true]);

        $result = $this->service->evaluate($directoryFirm);

        $this->assertFalse($result->eligible);
        $this->assertSame('no_canonical_firm', $result->reasonCode);
    }

    public function test_a_member_but_unpublished_draft_listing_is_denied(): void
    {
        $firm = Firm::factory()->create();
        $directoryFirm = DirectoryFirm::factory()->member()->draft()->create([
            'firm_id' => $firm->id,
            'accepting_inquiries' => true,
        ]);

        $result = $this->service->evaluate($directoryFirm);

        $this->assertFalse($result->eligible);
        $this->assertSame('not_published', $result->reasonCode);
    }

    public function test_a_member_but_suspended_listing_is_denied(): void
    {
        $firm = Firm::factory()->create();
        $directoryFirm = DirectoryFirm::factory()->member()->suspended()->create([
            'firm_id' => $firm->id,
            'accepting_inquiries' => true,
        ]);

        $result = $this->service->evaluate($directoryFirm);

        $this->assertFalse($result->eligible);
        $this->assertSame('not_published', $result->reasonCode);
    }

    public function test_a_member_published_but_not_accepting_inquiries_listing_is_denied(): void
    {
        $firm = Firm::factory()->create();
        $directoryFirm = DirectoryFirm::factory()->member()->create([
            'firm_id' => $firm->id,
            'accepting_inquiries' => false,
        ]);

        $result = $this->service->evaluate($directoryFirm);

        $this->assertFalse($result->eligible);
        $this->assertSame('not_accepting_inquiries', $result->reasonCode);
    }

    public function test_value_object_static_constructors(): void
    {
        $eligible = MarketplaceIntakeEligibility::eligible();
        $ineligible = MarketplaceIntakeEligibility::ineligible('unclaimed');

        $this->assertTrue($eligible->eligible);
        $this->assertNull($eligible->reasonCode);
        $this->assertFalse($ineligible->eligible);
        $this->assertSame('unclaimed', $ineligible->reasonCode);
    }
}
