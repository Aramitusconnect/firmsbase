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
                && ! str_starts_with($path, 'tests/Feature/SupportAccess/')
                && ! str_starts_with($path, 'app/Http/Middleware/')
                && ! str_starts_with($path, 'app/Support/')
                // Section 39A-2 legitimately added test helper methods
                // to tests/TestCase.php.
                && $path !== 'tests/TestCase.php'
                // Section 39A-3A (a later, distinct staged-FORCE-
                // activation branch) legitimately added a clients-only
                // FORCE RLS migration, a ClientFactory context fix,
                // and updated tests for the real services it also
                // wired with explicit tenant context.
                && $path !== 'database/migrations/2026_07_30_900001_force_rls_on_clients_table.php'
                && $path !== 'database/factories/ClientFactory.php'
                && $path !== 'tests/Feature/Imports/ImportApplyServiceTest.php'
                && $path !== 'tests/Feature/Imports/ImportRollbackServiceTest.php'
                && $path !== 'tests/Feature/Webhooks/Wiring/ClientCreatedWiringTest.php'
                // Section 39A-3B (a later, distinct staged-FORCE-
                // activation branch) legitimately added a
                // firm_users-only FORCE RLS migration, a
                // FirmUserFactory context fix, and updated the
                // legitimately cross-firm relationship tests it
                // affected.
                && $path !== 'database/migrations/2026_07_31_900001_force_rls_on_firm_users_table.php'
                && $path !== 'database/factories/FirmUserFactory.php'
                && $path !== 'tests/Feature/Identity/FirmUserTest.php'
                && $path !== 'tests/Feature/Identity/UserFirmRelationshipsTest.php'
                && $path !== 'tests/Feature/Tenancy/RowLevelSecurityPreparationTest.php'
                // Section 39A-3C (a later, distinct staged-FORCE-
                // activation branch) legitimately added a
                // documents-only FORCE RLS migration, a DocumentFactory
                // context fix, and updated the tests it affected.
                && $path !== 'database/migrations/2026_08_01_900001_force_rls_on_documents_table.php'
                && $path !== 'database/factories/DocumentFactory.php'
                && $path !== 'tests/Feature/Documents/DocumentReplacementServiceTest.php'
                && $path !== 'tests/Feature/Webhooks/Wiring/DocumentUploadedWiringTest.php'
                && $path !== 'tests/Feature/Signature/Certificates/SignatureCertificateOnePerRequestTest.php'
                && $path !== 'tests/Feature/Signature/Certificates/SignatureCertificateRequiresHashAndEventTrailTest.php'
                && $path !== 'tests/Feature/Signature/Certificates/SignatureCertificateServiceTest.php'
                // Section 39A-3D (a later, distinct staged-FORCE-
                // activation branch) legitimately added a
                // deadlines-only FORCE RLS migration, a DeadlineFactory
                // context fix, and explicit tenant-context wiring in
                // DeadlineService.
                && $path !== 'database/migrations/2026_08_02_900001_force_rls_on_deadlines_table.php'
                && $path !== 'database/factories/DeadlineFactory.php'
                // Section 39A-3E (a later, distinct staged-FORCE-
                // activation branch) legitimately added a tasks-only
                // FORCE RLS migration, a TaskFactory context fix, and
                // explicit tenant-context wiring in TaskService,
                // TaskDependencyService, and MatterReadinessService
                // (app/Services/ is already excluded above, so only
                // the migration/factory/affected tests need listing
                // here).
                && $path !== 'database/migrations/2026_08_03_900001_force_rls_on_tasks_table.php'
                && $path !== 'database/factories/TaskFactory.php'
                && $path !== 'tests/Feature/Tasks/TaskDependencyServiceTest.php'
                && $path !== 'tests/Feature/Webhooks/Wiring/TaskCompletedWiringTest.php'
                // Section 39A-3F (a later, distinct staged-FORCE-
                // activation branch) legitimately added a matters-only
                // FORCE RLS migration, a MatterFactory root-cause
                // firm/client consistency fix, explicit tenant-context
                // wiring in real services (app/Services/ is already
                // excluded above, so only the migration/factory/
                // affected tests need listing here), and updated the
                // tests it affected.
                && $path !== 'database/migrations/2026_08_04_900001_force_rls_on_matters_table.php'
                && $path !== 'database/factories/MatterFactory.php'
                && $path !== 'tests/Feature/Matters/MatterOpeningServiceTest.php'
                && $path !== 'tests/Feature/MobilePortal/MobilePortalReadinessServiceTest.php'
                && $path !== 'tests/Feature/PilotWorkflow/ProductionPilotWorkflowServiceTest.php'
                && $path !== 'tests/Feature/Webhooks/Wiring/MatterCreatedWiringTest.php'
                && $path !== 'tests/Feature/Webhooks/Wiring/MatterReadinessChangedWiringTest.php'
                // Section 39A-3G (a later, distinct staged-FORCE-
                // activation branch) legitimately added an
                // invoices-only FORCE RLS migration, an InvoiceFactory
                // root-cause firm/client consistency fix, explicit
                // tenant-context wiring in real services
                // (app/Services/ is already excluded above, so only
                // the migration/factory/affected tests need listing
                // here), and updated the tests it affected.
                && $path !== 'database/migrations/2026_08_05_900001_force_rls_on_invoices_table.php'
                && $path !== 'database/factories/InvoiceFactory.php'
                && $path !== 'tests/Feature/Invoicing/InvoiceDraftingServiceTest.php'
                && $path !== 'tests/Feature/Payments/PaymentApplicationServiceTest.php'
                && $path !== 'tests/Feature/Trust/Transfers/TrustTransferRequestServiceTest.php'
                // Section 39A-3H (a later, distinct staged-FORCE-
                // activation branch) legitimately added a
                // payments-only FORCE RLS migration, a PaymentFactory
                // root-cause firm/client consistency fix, explicit
                // tenant-context wiring in real services
                // (app/Services/ is already excluded above, so only
                // the migration/factory/affected tests need listing
                // here), and updated the tests it affected.
                && $path !== 'database/migrations/2026_08_06_900001_force_rls_on_payments_table.php'
                && $path !== 'database/factories/PaymentFactory.php'
                && $path !== 'tests/Feature/Payments/ManualPaymentServiceTest.php'
                && $path !== 'tests/Feature/Webhooks/Wiring/PaymentRecordedWiringTest.php'
                // Section 40 (a later, distinct limited-pilot-safety-
                // gate branch) legitimately added its own read-only
                // gate service (app/Services/ is already excluded
                // above), its own test directory (tests/Feature/
                // Governance/ is already excluded above), and a
                // markdown report under docs/governance/.
                && $path !== 'docs/governance/section-40-limited-pilot-safety-gate.md',
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
