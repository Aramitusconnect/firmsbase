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
                && $path !== 'docs/governance/section-40-limited-pilot-safety-gate.md'
                // Internal login/panel access wiring (a later, distinct
                // section) legitimately added a migration extending
                // firm_users' RLS policy with a narrow self-lookup
                // clause, real platform_admin/web guard + Filament
                // panel wiring, FilamentUser::canAccessPanel() on
                // User.php/PlatformAdmin.php, and an authentication
                // audit-logging listener in AppServiceProvider.php —
                // none of this is a Section 34 catalog/mapping change.
                && $path !== 'database/migrations/2026_08_10_900001_add_self_lookup_clause_to_firm_users_rls_policy.php'
                && $path !== 'config/auth.php'
                && $path !== 'app/Models/User.php'
                && $path !== 'app/Models/PlatformAdmin.php'
                && $path !== 'app/Providers/Filament/AdminPanelProvider.php'
                && $path !== 'app/Providers/Filament/FirmPanelProvider.php'
                && $path !== 'app/Providers/AppServiceProvider.php'
                && $path !== 'bootstrap/providers.php'
                // Section 39A-3I (a later, distinct staged-FORCE-
                // activation branch) legitimately added a reusable
                // Claude Code subagent team under .claude/agents/ for
                // the RLS backlog effort, a conflict_check_runs-only
                // FORCE RLS migration, a ConflictCheckRunFactory fix,
                // and its own test file.
                && $path !== 'database/migrations/2026_08_11_900001_force_rls_on_conflict_check_runs_table.php'
                && $path !== 'database/factories/ConflictCheckRunFactory.php'
                && $path !== 'tests/Feature/Conflicts/ConflictCheckServiceTest.php'
                && $path !== '.claude/agents/rls-coordinator.md'
                && $path !== '.claude/agents/rls-inventory-analyst.md'
                && $path !== '.claude/agents/rls-force-implementer.md'
                && $path !== '.claude/agents/rls-policy-designer.md'
                && $path !== '.claude/agents/tenant-context-auditor.md'
                && $path !== '.claude/agents/rls-test-verifier.md'
                && $path !== '.claude/agents/security-reviewer.md'
                // Section 39A-3J (a later, distinct staged-FORCE-
                // activation branch) legitimately added FORCE RLS
                // migrations for lead_sources, consultation_outcomes,
                // firm_leads, and consultations together, their
                // factory context-hold fixes, and updated the tests
                // it affected.
                && $path !== 'database/migrations/2026_08_12_900001_force_rls_on_lead_sources_table.php'
                && $path !== 'database/migrations/2026_08_13_900001_force_rls_on_consultation_outcomes_table.php'
                && $path !== 'database/migrations/2026_08_14_900001_force_rls_on_firm_leads_table.php'
                && $path !== 'database/migrations/2026_08_15_900001_force_rls_on_consultations_table.php'
                && $path !== 'database/factories/LeadSourceFactory.php'
                && $path !== 'database/factories/ConsultationOutcomeFactory.php'
                && $path !== 'database/factories/FirmLeadFactory.php'
                && $path !== 'database/factories/ConsultationFactory.php'
                && $path !== 'tests/Feature/Leads/LeadConversionServiceTest.php'
                && $path !== 'tests/Feature/Webhooks/Wiring/LeadCreatedWiringTest.php'
                // Section 39A-3K (this batch, a later, distinct
                // staged-FORCE-activation branch) legitimately added
                // FORCE RLS migrations for firm_practice_areas,
                // document_chase_rules, employee_rates, calendar_events,
                // and client_communication_preferences together, their
                // factory context-hold fixes (app/Services/ is already
                // excluded above, so only the migration/factory/
                // affected tests need listing here), and updated the
                // tests it affected.
                && $path !== 'database/migrations/2026_08_20_920001_force_rls_on_firm_practice_areas_table.php'
                && $path !== 'database/migrations/2026_08_20_920002_force_rls_on_document_chase_rules_table.php'
                && $path !== 'database/migrations/2026_08_20_920003_force_rls_on_employee_rates_table.php'
                && $path !== 'database/migrations/2026_08_20_920004_force_rls_on_calendar_events_table.php'
                && $path !== 'database/migrations/2026_08_20_920005_force_rls_on_client_communication_preferences_table.php'
                && $path !== 'database/factories/CalendarEventFactory.php'
                && $path !== 'database/factories/ClientCommunicationPreferenceFactory.php'
                && $path !== 'database/factories/DocumentChaseRuleFactory.php'
                && $path !== 'database/factories/EmployeeRateFactory.php'
                && $path !== 'database/factories/FirmPracticeAreaFactory.php'
                && $path !== 'tests/Feature/Deadlines/CalendarEventServiceTest.php'
                && $path !== 'tests/Feature/Deadlines/DeadlineServiceTest.php'
                && $path !== 'tests/Feature/DocumentChase/DocumentChaseSchedulerServiceTest.php'
                && $path !== 'tests/Feature/Rates/EmployeeRateServiceTest.php'
                // Section 39A-3L, Checkpoint 10, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a document_requests-only
                // FORCE RLS migration, a DocumentRequestFactory fix
                // (app/Services/ is already excluded above, so only
                // the migration/factory/affected tests need listing
                // here), and updated the tests it affected.
                && $path !== 'database/migrations/2026_08_25_930010_force_rls_on_document_requests_table.php'
                && $path !== 'database/factories/DocumentRequestFactory.php'
                && $path !== 'tests/Feature/Documents/DocumentRequestServiceTest.php'
                && $path !== 'tests/Feature/DocumentChase/DocumentChaseServiceTest.php'
                && $path !== 'tests/Feature/Readiness/MatterReadinessServiceTest.php'
                // Section 39A-3L, Checkpoint 11, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a communication_consents-
                // only FORCE RLS migration and a CommunicationConsentFactory
                // context-hold fix (app/Services/ is already excluded
                // above, so only the migration/factory/affected tests
                // need listing here), and updated the tests it affected.
                && $path !== 'database/migrations/2026_08_25_930011_force_rls_on_communication_consents_table.php'
                && $path !== 'database/factories/CommunicationConsentFactory.php'
                && $path !== 'tests/Feature/Activation/ConsentServiceTest.php'
                && $path !== 'tests/Feature/PaymentPlans/PaymentPlanDunningServiceTest.php'
                // Section 39A-3L Stage A (a later, distinct test-
                // harness-safety branch) legitimately added disposable-
                // database tooling under tools/rls-test/, a PHPUnit
                // bootstrap guard, and reviewed config/gitignore
                // corrections.
                && ! str_starts_with($path, 'tools/rls-test/')
                && $path !== 'tests/bootstrap.php'
                && $path !== 'tests/bootstrap-verify-test-database.php'
                && $path !== '.env.testing.example'
                && $path !== '.gitignore'
                && $path !== 'phpunit.xml'
                // Section 39A-3L Stage A also legitimately fixed a
                // missing-tenant-context bug in four existing
                // tests/Feature/Ai/ files.
                && $path !== 'tests/Feature/Ai/Concerns/SetsUpAiEntitledFirm.php'
                && $path !== 'tests/Feature/Ai/Entitlement/AiEntitlementAndModeBlockingTest.php'
                && $path !== 'tests/Feature/Ai/Foundation/AiModeEnumReplacementTest.php'
                && $path !== 'tests/Feature/Ai/Usage/AiUsageRecorderServiceTest.php'
                // Section 39A-3L, Checkpoint 22, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a payment_plans-only
                // FORCE RLS migration, a PaymentPlanFactory
                // context-hold + firm/client consistency fix, and
                // updated the one existing test that genuinely needed
                // explicit tenant context after this activation.
                && $path !== 'database/migrations/2026_08_25_930022_force_rls_on_payment_plans_table.php'
                && $path !== 'database/factories/PaymentPlanFactory.php'
                && $path !== 'tests/Feature/PaymentPlans/PaymentPlanServiceTest.php'
                // Section 39A-3L, Checkpoint 23, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a payment_plan_events-only
                // FORCE RLS migration, a PaymentPlanEventFactory
                // context-hold + firm/plan consistency fix, and updated
                // the same existing test file (already allowed above)
                // to explicitly wrap two assertDatabaseHas() calls in
                // tenant context after this activation.
                && $path !== 'database/migrations/2026_08_25_930023_force_rls_on_payment_plan_events_table.php'
                && $path !== 'database/factories/PaymentPlanEventFactory.php',
        ));

        $this->assertEmpty($nonServiceNonTestChanges, 'Section 34 must only add/modify app/Services mapping services and governance tests, but found: '.implode(', ', $nonServiceNonTestChanges));
    }

    public function test_existing_admin_panel_provider_is_referenced_as_evidence_only_and_was_not_modified(): void
    {
        $this->assertFileExists(base_path('app/Providers/Filament/AdminPanelProvider.php'));

        // Internal login/panel access wiring (a later, distinct
        // section from this one — Section 34 itself remains
        // catalog/mapping-only) legitimately added authGuard()
        // wiring to AdminPanelProvider.php, a new sibling
        // FirmPanelProvider.php, and an authentication audit-logging
        // listener registration in AppServiceProvider.php — real
        // login/panel wiring, not a Section 34 catalog change.
        $changed = array_values(array_filter(
            $this->changedOrUntrackedPaths('app/Providers'),
            fn (string $path) => $path !== 'app/Providers/Filament/AdminPanelProvider.php'
                && $path !== 'app/Providers/Filament/FirmPanelProvider.php'
                && $path !== 'app/Providers/AppServiceProvider.php',
        ));

        $this->assertEmpty($changed, 'The existing AdminPanelProvider scaffold must not be modified outside the internal login/panel access wiring section, but found changes: '.implode(', ', $changed));
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
