<?php

namespace Tests\Feature\Governance\CrossCutting;

use App\Services\AccessibilityCoverageMappingService;
use Tests\TestCase;

class AccessibilityCoverageMappingServiceTest extends TestCase
{
    private const REQUIRED_SURFACES = [
        'client_portal',
        'payment_flows',
        'payment_plan_flows',
        'legal_form_workflows',
        'e_signature_screens',
    ];

    private AccessibilityCoverageMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AccessibilityCoverageMappingService();
    }

    public function test_all_five_surfaces_are_declared(): void
    {
        $items = $this->service->all();

        $this->assertCount(5, $items);

        $declaredKeys = array_map(fn ($item) => $item->item_key, $items);

        foreach (self::REQUIRED_SURFACES as $surface) {
            $this->assertContains($surface, $declaredKeys, "Missing required accessibility surface: {$surface}");
        }
    }

    public function test_client_portal_maps_to_client_portal_accessibility_readiness_service(): void
    {
        $this->assertSame(
            \App\Services\ClientPortalAccessibilityReadinessService::class,
            $this->service->bySurface('client_portal')->owning_class,
        );
    }

    public function test_payment_and_payment_plan_surfaces_map_to_billing_accessibility_readiness_service(): void
    {
        $this->assertSame(
            \App\Services\BillingAccessibilityReadinessService::class,
            $this->service->bySurface('payment_flows')->owning_class,
        );
        $this->assertSame(
            \App\Services\BillingAccessibilityReadinessService::class,
            $this->service->bySurface('payment_plan_flows')->owning_class,
        );
    }

    public function test_legal_forms_map_to_form_accessibility_readiness_service(): void
    {
        $this->assertSame(
            \App\Services\FormAccessibilityReadinessService::class,
            $this->service->bySurface('legal_form_workflows')->owning_class,
        );
    }

    public function test_e_signature_maps_to_signature_accessibility_readiness_service(): void
    {
        $this->assertSame(
            \App\Services\SignatureAccessibilityReadinessService::class,
            $this->service->bySurface('e_signature_screens')->owning_class,
        );
    }

    public function test_by_surface_returns_null_for_an_unknown_surface(): void
    {
        $this->assertNull($this->service->bySurface('does_not_exist'));
    }

    public function test_has_renderable_ui_surface_is_false_because_no_ui_exists(): void
    {
        $this->assertFalse($this->service->hasRenderableUiSurface());
    }

    public function test_missing_surfaces_lists_all_five_surfaces_while_no_ui_exists(): void
    {
        $missing = $this->service->missingSurfaces();

        sort($missing);
        $expected = self::REQUIRED_SURFACES;
        sort($expected);

        $this->assertSame($expected, $missing);
    }

    public function test_no_blade_filament_livewire_frontend_or_browser_accessibility_files_exist(): void
    {
        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));

        $bladeFiles = [];
        $viewsDir = resource_path('views');

        if (is_dir($viewsDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($viewsDir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    $bladeFiles[] = $file->getPathname();
                }
            }
        }

        // Only the default Laravel scaffold welcome view may exist.
        $nonDefaultBladeFiles = array_values(array_filter(
            $bladeFiles,
            fn (string $path) => basename($path) !== 'welcome.blade.php',
        ));

        $this->assertEmpty($nonDefaultBladeFiles, 'Found unexpected Blade files: '.implode(', ', $nonDefaultBladeFiles));

        $forbiddenTokens = ['Dusk', 'axe-core', 'pa11y'];
        $packageJsonPath = base_path('package.json');

        if (is_file($packageJsonPath)) {
            $packageJson = file_get_contents($packageJsonPath);

            foreach ($forbiddenTokens as $token) {
                $this->assertStringNotContainsString($token, $packageJson, "package.json must not reference browser accessibility tooling: {$token}");
            }
        }
    }
}
