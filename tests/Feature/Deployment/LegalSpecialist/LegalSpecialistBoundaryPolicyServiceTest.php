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

        // Checkpoint 18 activated FORCE ROW LEVEL SECURITY on
        // firm_settings; a bare, unwrapped update()/fresh() against it
        // with no ambient tenant context silently affects/returns zero
        // rows rather than throwing, which made this test's own setup a
        // no-op. Wrapped narrowly (write + the refresh that reloads the
        // firmSettings relation) so the assertion below actually
        // exercises the true-value precondition it claims to test.
        $firm = $this->runWithFirmContext($firm, function () use ($firm) {
            $firm->firmSettings()->update(['trust_iolta_protection' => true]);

            return $firm->fresh(['firmSettings']);
        });

        $this->expectException(\RuntimeException::class);
        $this->service->assertTrustIoltaNeverEnabledFor($firm);
    }

    public function test_law_firm_may_have_trust_iolta_protection_enabled(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::Dedicated, CustomerType::LawFirm);

        // Same FORCE-RLS wrapping as the legal_specialist test above,
        // for consistency — this test doesn't currently fail without it
        // (its assertion is trivially satisfied either way), but leaving
        // an identical bare write "unfixed" two lines from the fixed one
        // would be inconsistent and could mask a real bug if this
        // assertion is ever tightened later.
        $firm = $this->runWithFirmContext($firm, function () use ($firm) {
            $firm->firmSettings()->update(['trust_iolta_protection' => true]);

            return $firm->fresh(['firmSettings']);
        });

        // Should not throw.
        $this->service->assertTrustIoltaNeverEnabledFor($firm);
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
