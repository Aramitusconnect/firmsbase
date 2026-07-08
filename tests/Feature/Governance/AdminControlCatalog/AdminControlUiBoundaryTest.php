<?php

namespace Tests\Feature\Governance\AdminControlCatalog;

use Tests\TestCase;

/**
 * AdminControlUiBoundaryTest — proves Section 34 remained catalog/
 * mapping-only: no routes/controllers/Filament/Blade/Livewire files
 * were added or modified, no admin resources/pages were generated,
 * and the existing (empty) Filament AdminPanelProvider scaffold — the
 * one real piece of admin-panel evidence AWS found — was only ever
 * referenced as evidence, never modified.
 */
class AdminControlUiBoundaryTest extends TestCase
{
    public function test_no_routes_controllers_filament_blade_or_livewire_files_were_added_or_modified(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "Section 34 must not add or modify any UI/route file, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }
    }

    public function test_no_admin_resources_or_pages_were_generated(): void
    {
        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));

        $controllerFiles = glob(base_path('app/Http/Controllers/*.php')) ?: [];
        $this->assertSame(['Controller.php'], array_map('basename', $controllerFiles), 'No real controller should exist beyond the empty Laravel scaffold.');
    }

    public function test_this_section_remains_catalog_mapping_only(): void
    {
        $changedRepoWide = $this->changedOrUntrackedPaths('.');

        $nonServiceNonTestChanges = array_values(array_filter(
            $changedRepoWide,
            fn (string $path) => $path !== 'database/seeders/DatabaseSeeder.php'
                && $path !== 'database/migrations/2026_07_29_900001_add_firm_user_2fa_mode_to_firm_settings_table.php'
                && $path !== 'app/Models/FirmSettings.php'
                && ! str_starts_with($path, 'app/Services/')
                && ! str_starts_with($path, 'tests/Feature/Governance/')
                && ! str_starts_with($path, 'tests/Feature/Security/')
                && ! str_starts_with($path, 'tests/Feature/SupportAccess/'),
        ));

        $this->assertEmpty($nonServiceNonTestChanges, 'Section 34 must only add/modify app/Services mapping services and governance tests, but found: '.implode(', ', $nonServiceNonTestChanges));
    }

    public function test_existing_admin_panel_provider_is_referenced_as_evidence_only_and_was_not_modified(): void
    {
        $this->assertFileExists(base_path('app/Providers/Filament/AdminPanelProvider.php'));

        $changed = $this->changedOrUntrackedPaths('app/Providers');

        $this->assertEmpty($changed, 'The existing AdminPanelProvider scaffold must not be modified, but found changes: '.implode(', ', $changed));
    }

    /**
     * @return array<int, string>
     */
    private function changedOrUntrackedPaths(string $scope): array
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg($scope)
        ));

        if ($changed === '') {
            return [];
        }

        return preg_split('/\R/', $changed) ?: [];
    }
}
