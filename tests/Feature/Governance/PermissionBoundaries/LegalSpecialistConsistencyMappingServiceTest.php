<?php

namespace Tests\Feature\Governance\PermissionBoundaries;

use App\Enums\GovernanceMappingStatus;
use App\Services\LegalSpecialistBoundaryPolicyService;
use App\Services\LegalSpecialistConsistencyMappingService;
use Tests\Concerns\EvaluatesHistoricalCheckpointScope;
use Tests\TestCase;

class LegalSpecialistConsistencyMappingServiceTest extends TestCase
{
    use EvaluatesHistoricalCheckpointScope;

    private const REQUIRED_SURFACES = [
        'dashboards',
        'portal',
        'emails',
        'invoices',
        'exports',
        'notifications',
    ];

    private LegalSpecialistConsistencyMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LegalSpecialistConsistencyMappingService;
    }

    public function test_declares_all_six_surfaces_explicitly(): void
    {
        $items = $this->service->all();

        $this->assertCount(6, $items);

        $declaredKeys = array_map(fn ($item) => $item->item_key, $items);

        foreach (self::REQUIRED_SURFACES as $surface) {
            $this->assertContains($surface, $declaredKeys, "Missing required surface: {$surface}");
        }
    }

    public function test_every_surface_uses_legal_specialist_boundary_policy_service_as_owning_class(): void
    {
        foreach ($this->service->all() as $item) {
            $this->assertSame(LegalSpecialistBoundaryPolicyService::class, $item->owning_class);
        }
    }

    public function test_dashboards_and_portal_are_not_applicable_yet_because_no_ui_exists(): void
    {
        $this->assertSame(GovernanceMappingStatus::NotApplicableYet, $this->service->bySurface('dashboards')->status);
        $this->assertSame(GovernanceMappingStatus::NotApplicableYet, $this->service->bySurface('portal')->status);
    }

    public function test_emails_invoices_exports_notifications_are_not_found(): void
    {
        foreach (['emails', 'invoices', 'exports', 'notifications'] as $surface) {
            $this->assertSame(GovernanceMappingStatus::NotFound, $this->service->bySurface($surface)->status, "{$surface} expected NotFound");
        }
    }

    public function test_by_surface_returns_null_for_an_unknown_surface(): void
    {
        $this->assertNull($this->service->bySurface('does_not_exist'));
    }

    public function test_missing_surfaces_includes_surfaces_not_yet_wired(): void
    {
        $missing = $this->service->missingSurfaces();

        sort($missing);
        $expected = ['emails', 'exports', 'invoices', 'notifications'];
        sort($expected);

        $this->assertSame($expected, $missing);
    }

    public function test_implemented_surfaces_is_empty_since_nothing_is_wired_in_yet(): void
    {
        $this->assertEmpty($this->service->implementedSurfaces());
    }

    public function test_no_second_terminology_system_was_created(): void
    {
        // The service under test must not modify LegalSpecialistBoundaryPolicyService
        // or introduce a parallel forbidden-terms denylist of its own.
        $changed = $this->changedOrUntrackedPathsRaw('app/Services/LegalSpecialistBoundaryPolicyService.php');
        $this->assertSame('', $changed);

        $source = file_get_contents(app_path('Services/LegalSpecialistConsistencyMappingService.php'));
        $this->assertStringNotContainsString("'trust account'", $source);
        $this->assertStringNotContainsString('IOLTA', $source);
    }
}
