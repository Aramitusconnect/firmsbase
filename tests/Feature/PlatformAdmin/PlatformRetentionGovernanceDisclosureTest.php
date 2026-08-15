<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformRetentionGovernancePage;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use App\Services\RetentionGovernanceRegistryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Retention page's truthfulness guarantees, as distinct from its
 * rendering mechanics (covered by PlatformRetentionGovernancePageTest).
 *
 * These assert the disclosures a compliance operator depends on and
 * which are easy to erode accidentally during UI polish: that sweep
 * history is never presented as observed, that the retention_policies
 * RLS gap stays visible, and that every retention category carries an
 * explicit legal hold coverage verdict rather than a silent blank.
 */
final class PlatformRetentionGovernanceDisclosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function superAdmin(): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        return $admin;
    }

    private function pageContent(): string
    {
        $this->actingAs($this->superAdmin(), 'platform_admin');

        return $this->get(PlatformRetentionGovernancePage::getUrl())
            ->assertSuccessful()
            ->getContent();
    }

    public function test_the_page_never_claims_a_sweep_was_observed(): void
    {
        $content = $this->pageContent();

        $this->assertStringContainsString('no durable sweep evidence', strtolower($content));
        $this->assertStringNotContainsString('last successful sweep:', strtolower($content));
    }

    public function test_the_scheduled_cadence_is_labelled_configuration_not_evidence(): void
    {
        $content = strtolower($this->pageContent());

        $this->assertStringContainsString('configuration, not evidence', $content);
    }

    public function test_never_swept_and_zero_failures_are_explicitly_not_distinguished(): void
    {
        $this->assertStringContainsString(
            'cannot be distinguished here, so neither is claimed',
            strtolower($this->pageContent()),
        );
    }

    public function test_the_retention_policies_rls_gap_stays_disclosed(): void
    {
        $content = $this->pageContent();

        $this->assertStringContainsString('no row level security at all', $content);
        $this->assertStringContainsString('owner-approved schema/policy change', $content);
    }

    /**
     * §30: the phrase must not survive as a passive banner. Every
     * category gets a verdict, and an unresolved one names its cause and
     * what would close it.
     */
    public function test_every_retention_category_carries_an_explicit_hold_coverage_verdict(): void
    {
        $content = $this->pageContent();
        $registry = app(RetentionGovernanceRegistryService::class);

        $categories = $registry->categories();
        $unresolved = $registry->categoriesWithUnresolvedLegalHoldCoverage();

        $this->assertNotEmpty($categories);

        $verdictCount = substr_count($content, 'Legal hold coverage:');
        $this->assertSame(
            count($categories),
            $verdictCount,
            'every registry category must render exactly one legal hold coverage verdict'
        );

        $this->assertNotEmpty($unresolved, 'this HEAD genuinely has unresolved categories — see the mission findings');
        $this->assertStringContainsString('UNRESOLVED', $content);
        $this->assertStringContainsString('does not call LegalHoldService::checkHold()', $content);
        $this->assertStringContainsString('requires a backend resolution layer, not a UI change', $content);
    }

    public function test_a_fail_safe_category_is_not_presented_as_a_zero_day_window(): void
    {
        $content = $this->pageContent();

        $this->assertStringContainsString(
            'ships with no default on purpose; the sweep no-ops rather than guessing',
            $content,
            'a not-configured window must never collapse into "0 days"'
        );
    }

    /**
     * Action registration is proven at source level by
     * PlatformRetentionGovernancePageTest. What is checked here is the
     * narrower UI claim: no sweep-execution or download affordance is
     * offered, since neither capability exists behind this page.
     */
    public function test_the_page_offers_no_sweep_execution_or_download_affordance(): void
    {
        $content = strtolower($this->pageContent());

        foreach (['run sweep', 'execute sweep', 'download'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $content,
                "the retention page must not offer '{$forbidden}' — no such capability exists behind it"
            );
        }
    }
}
