<?php

namespace Tests\Feature\Trust\Eligibility;

use App\Enums\CustomerType;
use App\Enums\EntitlementSource;
use App\Enums\PaymentMode;
use App\Models\Firm;
use App\Models\FirmSettings;
use App\Services\EntitlementService;
use App\Services\TenantContextService;
use App\Services\TrustEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

/**
 * Correction #9: all five conditions are a strict AND — denying any one
 * of them must deny the whole decision, and the fully-set-up firm from
 * SetsUpTrustEligibleFirm (which exercises the real Phase 7 two-person
 * flow) must be allowed.
 */
class TrustEligibilityServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    private TrustEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TrustEligibilityService::class);
    }

    public function test_fully_configured_firm_is_eligible(): void
    {
        $firm = $this->makeTrustEligibleFirm();

        $decision = $this->service->evaluate($firm);

        $this->assertTrue($decision->allowed);
    }

    /**
     * Dedicated regression test for the Phase 4 round 1 gap (Wave 10
     * design doc §0b): hasApprovedTrustSetup()'s trust_approval_events
     * read needs its own tenant-context wrap, independent of the
     * pre-existing firm_settings wrap, since trust_approval_events is
     * now FORCE-RLS'd. test_fully_configured_firm_is_eligible() above
     * already proves this passes, but — empirically confirmed during
     * this review — that test alone does NOT actually exercise the
     * true no-context condition: makeTrustEligibleFirm()'s own many
     * factory/service calls leave ambient PostgreSQL session context
     * lingering at this same firm's id (factories' context-hold
     * create() pattern never clears it, and runWithFirmContext() only
     * ever restores to whatever was already ambient), which
     * accidentally matches the firm under test and would mask a
     * reintroduced version of this exact bug. This test explicitly
     * clears context first, reproducing the real condition every one
     * of the ~25 live call sites hits, and was confirmed (by
     * temporarily reverting the fix during this review) to genuinely
     * fail without it and pass only with the real fix in place.
     */
    public function test_fully_configured_firm_is_eligible_with_no_ambient_tenant_context(): void
    {
        $firm = $this->makeTrustEligibleFirm();

        (new TenantContextService)->clearDatabaseTenantContext();

        $decision = $this->service->evaluate($firm);

        $this->assertTrue($decision->allowed, $decision->reason ?? 'expected eligible, but decision was denied');
    }

    public function test_legal_specialist_customer_type_is_always_denied(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $firm->update(['customer_type' => CustomerType::LegalSpecialist]);

        $decision = $this->service->evaluate($firm->fresh());

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('law_firm', $decision->reason);
    }

    public function test_denied_when_trust_iolta_entitlement_not_enabled(): void
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create([
            'payment_mode' => PaymentMode::OperatingAndTrust,
            'trust_iolta_protection' => true,
        ]);

        $decision = $this->service->evaluate($firm);

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('trust_iolta', $decision->reason);
    }

    public function test_denied_when_payment_mode_is_not_operating_and_trust(): void
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create([
            'payment_mode' => PaymentMode::OperatingPaymentsOnly,
        ]);
        app(EntitlementService::class)->setForSource($firm, 'trust_iolta', EntitlementSource::AdminOverride, true);

        $decision = $this->service->evaluate($firm);

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('payment_mode', $decision->reason);
    }

    public function test_denied_when_trust_iolta_protection_explicitly_disabled(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $firm->firmSettings->update(['trust_iolta_protection' => false]);

        $decision = $this->service->evaluate($firm->fresh());

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('trust_iolta_protection', $decision->reason);
    }

    public function test_denied_when_no_approved_trust_mode_activation_exists(): void
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create([
            'payment_mode' => PaymentMode::OperatingAndTrust,
            'trust_iolta_protection' => true,
        ]);
        app(EntitlementService::class)->setForSource($firm, 'trust_iolta', EntitlementSource::AdminOverride, true);

        $decision = $this->service->evaluate($firm);

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('two-person approval', $decision->reason);
    }

    public function test_assert_eligible_throws_when_not_eligible(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->service->assertEligible($firm);
    }
}
