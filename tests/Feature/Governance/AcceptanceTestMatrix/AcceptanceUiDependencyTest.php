<?php

namespace Tests\Feature\Governance\AcceptanceTestMatrix;

use App\Services\AcceptanceTestMatrixMappingService;
use Tests\TestCase;

class AcceptanceUiDependencyTest extends TestCase
{
    private AcceptanceTestMatrixMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AcceptanceTestMatrixMappingService;
    }

    public function test_accessibility_mobile_group_is_not_applicable_yet_because_no_real_ui_surface_exists(): void
    {
        $notApplicableKeys = array_keys($this->service->notApplicableYet());

        foreach ($this->service->group('accessibility_mobile') as $key => $item) {
            $this->assertContains($key, $notApplicableKeys, "{$key} should be NotApplicableYet.");
        }

        $this->assertCount(8, $this->service->group('accessibility_mobile'));
    }

    public function test_web_session_dependent_security_controls_are_not_applicable_yet(): void
    {
        $notApplicableKeys = array_keys($this->service->notApplicableYet());

        foreach ([
            'security.two_factor_authentication', 'security.session_timeout',
            'security.csrf', 'security.rate_limit',
        ] as $key) {
            $this->assertContains($key, $notApplicableKeys, "{$key} should be NotApplicableYet (no web layer exists).");
        }
    }

    public function test_entitlements_ui_hidden_and_route_blocked_are_not_applicable_yet(): void
    {
        $notApplicableKeys = array_keys($this->service->notApplicableYet());

        $this->assertContains('entitlements.ui_hidden', $notApplicableKeys);
        $this->assertContains('entitlements.route_blocked', $notApplicableKeys);
    }

    public function test_not_applicable_yet_is_never_treated_as_a_product_gap(): void
    {
        foreach ($this->service->notApplicableYet() as $key => $item) {
            $this->assertStringNotContainsString('gap', strtolower($item->item_label));
            $this->assertStringContainsStringIgnoringCase('not a product gap', $item->notes);
        }

        // notApplicableYet() items must never appear in this section's gaps().
        $gapKeys = array_map(fn ($g) => $g->item_key, $this->service->gaps());
        $notApplicableKeys = array_keys($this->service->notApplicableYet());

        $this->assertEmpty(array_intersect($gapKeys, $notApplicableKeys));
    }

    public function test_ui_dependent_accessor_matches_not_applicable_yet(): void
    {
        $this->assertSame(
            array_keys($this->service->notApplicableYet()),
            array_keys($this->service->uiDependent()),
        );
    }
}
