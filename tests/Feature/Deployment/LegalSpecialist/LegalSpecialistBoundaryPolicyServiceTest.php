<?php

namespace Tests\Feature\Deployment\LegalSpecialist;

use App\Enums\CustomerType;
use App\Enums\DeploymentMode;
use App\Services\LegalSpecialistBoundaryPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Deployment\Concerns\SetsUpDeploymentFirm;
use Tests\TestCase;

/**
 * Project rule 7: legal_specialist customers must not see trust/IOLTA
 * workflows or law-firm-only terminology.
 */
class LegalSpecialistBoundaryPolicyServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpDeploymentFirm;

    private LegalSpecialistBoundaryPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LegalSpecialistBoundaryPolicyService::class);
    }

    public function test_asserts_trust_iolta_never_enabled_for_legal_specialist(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::Dedicated, CustomerType::LegalSpecialist);
        $firm->firmSettings()->update(['trust_iolta_protection' => true]);

        $this->expectException(\RuntimeException::class);
        $this->service->assertTrustIoltaNeverEnabledFor($firm->fresh(['firmSettings']));
    }

    public function test_law_firm_may_have_trust_iolta_protection_enabled(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::Dedicated, CustomerType::LawFirm);
        $firm->firmSettings()->update(['trust_iolta_protection' => true]);

        // Should not throw.
        $this->service->assertTrustIoltaNeverEnabledFor($firm->fresh(['firmSettings']));
        $this->assertTrue(true);
    }

    #[DataProvider('forbiddenTerms')]
    public function test_containsForbiddenTerminology_flags_each_forbidden_term(string $term): void
    {
        $this->assertTrue($this->service->containsForbiddenTerminology("This mentions {$term} in passing."));
    }

    public static function forbiddenTerms(): array
    {
        return [
            ['trust account'],
            ['IOLTA'],
            ['trust ledger'],
            ['trust accounting'],
            ['law firm'],
            ['attorney'],
            ['client trust'],
        ];
    }

    public function test_containsForbiddenTerminology_is_false_for_clean_text(): void
    {
        $this->assertFalse($this->service->containsForbiddenTerminology('This firm operates on payments only.'));
    }

    public function test_assertBoundarySafeOutput_throws_for_legal_specialist_forbidden_text(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::Dedicated, CustomerType::LegalSpecialist);

        $this->expectException(\RuntimeException::class);
        $this->service->assertBoundarySafeOutput($firm, 'Your IOLTA trust account is active.');
    }
}
