<?php

namespace Tests\Feature\Webhooks\Firewall;

use Tests\TestCase;

/**
 * Manifest boundary: UI/routes/controllers/Filament/Blade/Livewire must
 * NOT be created in Phase 14 unless explicitly proven necessary and
 * approved separately. This proves the phase-14-complete package
 * contains none of these, anywhere.
 */
class WebhookNoUiOrRoutesTest extends TestCase
{
    private const FORBIDDEN_PATH_FRAGMENTS = [
        'routes'.DIRECTORY_SEPARATOR,
        'Http'.DIRECTORY_SEPARATOR.'Controllers',
        'Filament'.DIRECTORY_SEPARATOR,
        'Livewire'.DIRECTORY_SEPARATOR,
    ];

    public function test_no_blade_files_exist_anywhere_in_the_phase_14_package(): void
    {
        $root = base_path();
        $bladeFiles = $this->filesMatching($root, '/\.blade\.php$/');
        $webhookRelated = array_filter($bladeFiles, fn ($f) => str_contains($f, 'Webhook') || str_contains($f, 'webhook'));

        $this->assertEmpty($webhookRelated, 'Found unexpected webhook-related Blade files: '.implode(', ', $webhookRelated));
    }

    public function test_no_routes_files_reference_webhook_management_endpoints(): void
    {
        $routesDir = base_path('routes');

        if (! is_dir($routesDir)) {
            $this->assertTrue(true);

            return;
        }

        $violations = [];

        foreach ($this->filesMatching($routesDir, '/\.php$/') as $file) {
            $source = file_get_contents($file);

            if (preg_match('/webhook.?subscription/i', $source)) {
                $violations[] = $file;
            }
        }

        $this->assertEmpty($violations, 'Found webhook subscription routes: '.implode(', ', $violations));
    }

    public function test_no_controller_files_exist_for_webhooks(): void
    {
        $controllersDir = base_path('app/Http/Controllers');

        if (! is_dir($controllersDir)) {
            $this->assertTrue(true);

            return;
        }

        $violations = array_filter(
            $this->filesMatching($controllersDir, '/\.php$/'),
            fn ($f) => str_contains($f, 'Webhook')
        );

        $this->assertEmpty($violations, 'Found webhook controller files: '.implode(', ', $violations));
    }

    /**
     * POST-PHASE-2-INTEGRATION-OPERATIONS-CENTER UPDATE (FirmsVault
     * Platform Admin Control Center mission, Phase 2 — "Integration
     * Operations Center"): this boundary originally protected against
     * building UI for the Phase 14 webhook DELIVERY/SUBSCRIPTION system
     * this file's own class docblock describes (`webhook_events`/
     * `WebhookSubscription`/`WebhookDelivery` — an entirely different,
     * unrelated feature: outbound webhook fan-out to subscriber URLs).
     * `App\Filament\Resources\WebhookEventResource` (+ its List/View
     * Pages) is a DIFFERENT, later-authorized surface entirely: a
     * read-only, cross-firm platform-admin oversight view over
     * `integration_inbound_webhook_events` — persisted, already-verified
     * INBOUND webhook receipts belonging to the separate Integration
     * domain (checkpoint-06/07), never the Phase 14 outbound
     * delivery/subscription system this test protects. Mirrors
     * FirmIntegrationSuperAdminBoundaryStructuralTest's own
     * already-established "cascade update" pattern for exactly this
     * situation (a later, legitimately-authorized phase's file name
     * happens to collide with an earlier phase's blanket keyword sweep)
     * — narrowly allowlisted by exact basename, not a weakening of the
     * underlying Phase 14 invariant, which continues to reject every
     * OTHER webhook-named Filament file unconditionally.
     */
    private const PHASE_2_INTEGRATION_OPERATIONS_CENTER_ALLOWED_BASENAMES = [
        'WebhookEventResource.php',
        'ListWebhookEvents.php',
        'ViewWebhookEvent.php',
    ];

    public function test_no_filament_resource_files_exist_for_webhooks(): void
    {
        $filamentDir = base_path('app/Filament');

        if (! is_dir($filamentDir)) {
            $this->assertTrue(true);

            return;
        }

        $violations = array_filter(
            $this->filesMatching($filamentDir, '/\.php$/'),
            fn ($f) => str_contains($f, 'Webhook') && ! in_array(basename($f), self::PHASE_2_INTEGRATION_OPERATIONS_CENTER_ALLOWED_BASENAMES, true)
        );

        $this->assertEmpty($violations, 'Found Filament webhook resource files: '.implode(', ', $violations));
    }

    public function test_no_livewire_component_files_exist_for_webhooks(): void
    {
        $livewireDir = base_path('app/Livewire');

        if (! is_dir($livewireDir)) {
            $this->assertTrue(true);

            return;
        }

        $violations = array_filter(
            $this->filesMatching($livewireDir, '/\.php$/'),
            fn ($f) => str_contains($f, 'Webhook')
        );

        $this->assertEmpty($violations, 'Found Livewire webhook component files: '.implode(', ', $violations));
    }

    public function test_no_phase_14_service_or_model_class_extends_a_controller_or_livewire_component(): void
    {
        $dirs = ['app/Services', 'app/Models', 'app/Jobs'];
        $violations = [];

        foreach ($dirs as $dir) {
            $fullDir = base_path($dir);

            if (! is_dir($fullDir)) {
                continue;
            }

            foreach ($this->filesMatching($fullDir, '/\.php$/') as $file) {
                if (! str_contains($file, 'Webhook')) {
                    continue;
                }

                $source = file_get_contents($file);

                if (preg_match('/extends\s+(Controller|Component)\b/', $source)) {
                    $violations[] = $file;
                }
            }
        }

        $this->assertEmpty($violations, 'Found webhook classes extending Controller/Livewire Component: '.implode(', ', $violations));
    }

    /**
     * @return string[]
     */
    private function filesMatching(string $dir, string $pattern): array
    {
        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (preg_match($pattern, $fileInfo->getFilename())) {
                $result[] = $fileInfo->getPathname();
            }
        }

        return $result;
    }
}
