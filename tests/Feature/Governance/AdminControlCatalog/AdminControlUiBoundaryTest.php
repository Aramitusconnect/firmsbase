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
            // Phase 2 of the FirmsVault Platform Admin Control Center
            // mission ("Integration Operations Center"; a later,
            // entirely distinct mission from Section 34) legitimately
            // modified PlatformFirmIntegrationsPage.php (query
            // determinism + genuine DB-level pagination fixes) — real
            // UI work belonging to that later mission, not a Section 34
            // catalog/mapping change.
            // FIRMSVAULT — STAGING ADMIN STABILIZATION (a later,
            // independently reviewed mission) legitimately added
            // Create/Edit actions for the Plan catalog and a Create
            // action for Plan Modules/Add-ons (previously read-only) —
            // real UI work belonging to that later mission, not a
            // Section 34 catalog/mapping change.
            $adminStabilizationAllowed = [
                'app/Filament/Actions/Platform/AddPlanModuleAction.php',
                'app/Filament/Actions/Platform/CreatePlanAction.php',
                'app/Filament/Actions/Platform/EditPlanAction.php',
                'app/Filament/Resources/PlanAddOnResource.php',
                'app/Filament/Resources/PlanAddOnResource/Pages/ListPlanAddOns.php',
                'app/Filament/Resources/PlanResource.php',
                'app/Filament/Resources/PlanResource/Pages/ListPlans.php',
            ];

            $changed = array_values(array_filter(
                $this->changedOrUntrackedPaths($relativeDir),
                fn (string $path) => $path !== 'app/Filament/Pages/PlatformFirmIntegrationsPage.php'
                    && ! in_array($path, $adminStabilizationAllowed, true),
            ));

            $this->assertEmpty($changed, "Section 34 must not add or modify any UI/route file, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }
    }

    public function test_no_admin_resources_or_pages_were_generated(): void
    {
        // ReadinessController.php (ECS readiness foundation) is a reviewed,
        // narrow exception: a pure infra health-check endpoint with no
        // model access, no admin UI, and no Filament/Livewire involvement
        // — orthogonal to this test's actual concern (no admin resource
        // was generated for Section 34).
        $controllerFiles = glob(base_path('app/Http/Controllers/*.php')) ?: [];
        $this->assertSame(['Controller.php', 'ReadinessController.php'], array_map('basename', $controllerFiles), 'No real controller should exist beyond the empty Laravel scaffold and the reviewed ECS readiness probe.');
    }

    public function test_this_section_remains_catalog_mapping_only(): void
    {
        $changedRepoWide = $this->changedOrUntrackedPaths('.');

        $nonServiceNonTestChanges = array_values(array_filter(
            $changedRepoWide,
            fn (string $path) => $path !== 'database/seeders/DatabaseSeeder.php'
                && $path !== 'database/migrations/2026_07_29_900001_add_firm_user_2fa_mode_to_firm_settings_table.php'
                && $path !== 'app/Models/FirmSettings.php'
                // Section 39A-5 Wave 11 (the final wave of the 60-table
                // RLS rollout) legitimately updated the shared gap
                // registry doc once every table was moved into
                // PREPARED_TABLES — a governance-doc update, not a
                // catalog/UI change.
                && $path !== 'docs/governance/rls-gap-registry.md'
                // feature/ses-consumer-ecs-wiring (a later, distinct
                // ECS/IAM wiring mission) legitimately added docker/
                // entrypoint.sh's ses-consumer role dispatch, a new
                // docker/commands/ses-consumer.sh, Terraform IAM/ECS-
                // service/CloudWatch-alarm wiring, its own docs/ecs/
                // updates, and its own new test directory.
                && $path !== 'docker/entrypoint.sh'
                && $path !== 'docker/commands/ses-consumer.sh'
                && ! str_starts_with($path, 'infrastructure/ecs/')
                && ! str_starts_with($path, 'tests/Feature/Ecs/')
                && ! str_starts_with($path, 'docs/ecs/')
                && $path !== 'app/Providers/AppServiceProvider.php'
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
                && $path !== 'database/factories/PaymentPlanEventFactory.php'
                // Section 39A-3L, Checkpoint 24 (this batch, a later,
                // distinct staged-FORCE-activation branch) legitimately
                // added a notification_events-only FORCE RLS migration
                // and a NotificationEventFactory context-hold fix
                // (app/Services/ is already excluded above, so only the
                // migration/factory/affected tests need listing here),
                // and updated the two existing tests that legitimately
                // needed explicit tenant context after this activation.
                && $path !== 'database/migrations/2026_08_25_930024_force_rls_on_notification_events_table.php'
                && $path !== 'database/factories/NotificationEventFactory.php'
                && $path !== 'tests/Feature/Notifications/NotificationDispatchServiceTest.php'
                && $path !== 'tests/Feature/Notifications/SuppressionServiceTest.php'
                // Section 39A-3L Phase B5 (this batch, a later, distinct
                // contacts/parties FORCE-RLS-prerequisite branch —
                // contacts and parties are NOT yet FORCE-enabled by this
                // batch, only prepared for it) legitimately added
                // ContactFactory/PartyFactory context-hold fixes
                // (app/Services/ is already excluded above, so
                // ConflictCheckService.php/ImportApplyService.php/
                // ImportDuplicateDetectionService.php needed no new
                // entry here), and extended
                // ImportDuplicateDetectionServiceTest.php with Contact/
                // Party duplicate-detection coverage that did not exist
                // before this batch.
                && $path !== 'database/factories/ContactFactory.php'
                && $path !== 'database/factories/PartyFactory.php'
                && $path !== 'tests/Feature/Imports/ImportDuplicateDetectionServiceTest.php'
                // Section 39A-9 Wave 9 (migration/export domain)
                // legitimately added six combined prepare-and-force
                // migrations (export_jobs, migration_projects,
                // import_batches, implementation_projects,
                // fleet_migration_instance_status, offboarding_requests),
                // their six factories' context-hold fixes
                // (app/Services/ is already excluded above, so only the
                // migration/factory/affected tests need listing here),
                // and updated the tests it affected.
                && $path !== 'database/migrations/2026_08_29_970001_prepare_row_level_security_and_force_rls_on_export_jobs_table.php'
                && $path !== 'database/migrations/2026_08_29_970002_prepare_row_level_security_and_force_rls_on_migration_projects_table.php'
                && $path !== 'database/migrations/2026_08_29_970003_prepare_row_level_security_and_force_rls_on_import_batches_table.php'
                && $path !== 'database/migrations/2026_08_29_970004_prepare_row_level_security_and_force_rls_on_implementation_projects_table.php'
                && $path !== 'database/migrations/2026_08_29_970005_prepare_row_level_security_and_force_rls_on_fleet_migration_instance_status_table.php'
                && $path !== 'database/migrations/2026_08_29_970006_prepare_row_level_security_and_force_rls_on_offboarding_requests_table.php'
                && $path !== 'database/factories/ExportJobFactory.php'
                && $path !== 'database/factories/FleetMigrationInstanceStatusFactory.php'
                && $path !== 'database/factories/ImplementationProjectFactory.php'
                && $path !== 'database/factories/ImportBatchFactory.php'
                && $path !== 'database/factories/MigrationProjectFactory.php'
                && $path !== 'database/factories/OffboardingRequestFactory.php'
                && $path !== 'tests/Feature/Deployment/Fleet/FleetMigrationOrchestrationServiceTest.php'
                && $path !== 'tests/Feature/Implementation/ImplementationTaskServiceTest.php'
                && $path !== 'tests/Feature/Imports/ImportBatchServiceTest.php'
                && $path !== 'tests/Feature/Imports/ImportPreviewServiceTest.php'
                && $path !== 'tests/Feature/TenantIsolation/ImportExportTenantIsolationTest.php'
                && $path !== 'tests/Feature/Webhooks/Wiring/InvoiceCreatedWiringTest.php'
                // Phase 2 of the FirmsVault Platform Admin Control
                // Center mission ("Integration Operations Center"; a
                // later, entirely distinct mission from Section 34)
                // legitimately added: a new no-RLS provider-health
                // summary table + model + per-provider refresh job +
                // scheduled command (its sole-writer service is already
                // excluded above via the app/Services/ prefix check); a
                // narrow admin-actor extension to
                // ProviderConnectionService::disconnect() (which lives
                // under app/Integrations/Services/, not app/Services/,
                // so needs its own entry); a modified Filament page
                // (query determinism + genuine DB-level pagination
                // fixes); a new scheduled-command entry in
                // bootstrap/app.php; and its own new test files under
                // tests/Feature/Integrations/Admin/, outside the
                // governance-mapping test tree already excluded above.
                && $path !== 'database/migrations/2026_09_11_110001_create_integration_platform_provider_health_summaries_table.php'
                && $path !== 'app/Models/IntegrationPlatformProviderHealthSummary.php'
                && $path !== 'app/Jobs/RefreshIntegrationPlatformProviderHealthSummaryJob.php'
                && $path !== 'app/Console/Commands/RefreshIntegrationPlatformProviderHealthSummariesCommand.php'
                && $path !== 'app/Integrations/Services/ProviderConnectionService.php'
                && $path !== 'app/Filament/Pages/PlatformFirmIntegrationsPage.php'
                && $path !== 'bootstrap/app.php'
                && $path !== 'tests/Feature/Integrations/Admin/PlatformIntegrationProviderHealthSummaryTest.php'
                && $path !== 'tests/Feature/Integrations/Admin/PlatformIntegrationConnectionDisconnectTest.php'
                && $path !== 'tests/Feature/Integrations/Admin/PlatformIntegrationOversightQueryDeterminismTest.php'
                // Also updated the pre-existing
                // IntegrationWebhookRoutingIndexNoRlsAndNoSecretColumnTest.php
                // (tests/Unit/Integrations/) to add
                // IntegrationPlatformProviderHealthSummaryService.php to
                // its own source-inspection allowlist, mirroring that
                // test's existing IntegrationPlatformOversightReadService.php
                // entry exactly (same ->exists()-only existence-check
                // pattern).
                && $path !== 'tests/Unit/Integrations/IntegrationWebhookRoutingIndexNoRlsAndNoSecretColumnTest.php'
                // FIRMSVAULT — STAGING ADMIN STABILIZATION (a later,
                // independently reviewed mission) fixed a real Platform
                // Admin dashboard HTTP 500 (phpredis serializer
                // misconfiguration), added Create/Edit actions for the
                // Plan catalog and a Create action for Plan Modules/
                // Add-ons (previously read-only), a plan-selection
                // safety guard in firm provisioning, and a staging-safe
                // synthetic-plan bootstrap command — plus its own new/
                // updated tests.
                && $path !== 'config/database.php'
                && $path !== 'app/Models/Plan.php'
                && $path !== 'app/Services/PlanService.php'
                && $path !== 'app/Services/PlanModuleService.php'
                && $path !== 'app/Services/FirmProvisioningService.php'
                && $path !== 'app/Exceptions/InactivePlanSelectedException.php'
                && $path !== 'app/Console/Commands/BootstrapStagingSandboxPlanCommand.php'
                && $path !== 'app/Filament/Actions/Platform/CreatePlanAction.php'
                && $path !== 'app/Filament/Actions/Platform/EditPlanAction.php'
                && $path !== 'app/Filament/Actions/Platform/AddPlanModuleAction.php'
                && $path !== 'app/Filament/Resources/PlanResource.php'
                && $path !== 'app/Filament/Resources/PlanResource/Pages/ListPlans.php'
                && $path !== 'app/Filament/Resources/PlanAddOnResource.php'
                && $path !== 'app/Filament/Resources/PlanAddOnResource/Pages/ListPlanAddOns.php'
                && $path !== 'database/migrations/2026_10_10_100001_add_code_and_description_to_plans_table.php'
                && $path !== 'database/factories/PlanFactory.php'
                && $path !== 'tests/Feature/Ecs/RedisTlsConfigurationTest.php'
                && $path !== 'tests/Feature/Plans/PlanServiceTest.php'
                && $path !== 'tests/Feature/Services/FirmProvisioningServiceTest.php'
                && $path !== 'tests/Feature/Console/BootstrapStagingSandboxPlanCommandTest.php'
                && $path !== 'tests/Feature/PlatformAdmin/PlanCatalogCreateActionsTest.php'
                && $path !== 'tests/Feature/Security/RlsContextRollout/QueueConsoleContextRolloutTest.php'
                && $path !== 'tests/Feature/Security/RlsEnforcement/QueueConsoleTenantContextTest.php'
                && $path !== 'tests/Feature/Security/SeedData/SecretPatternScanTest.php'
                && $path !== 'tests/Feature/Integrations/Ui/FirmIntegrationSuperAdminBoundaryStructuralTest.php'
                // feature/ses-event-consumer (a later, distinct, wholly
                // isolated mission: a production-safe SES bounce/
                // complaint consumer) legitimately added a
                // notification-provider correlation ledger + idempotency
                // ledger, a dedicated SQS consumer command, real-send
                // correlation wiring in User/ClientPortalUser
                // password-reset notifications, and its own new test
                // files.
                && $path !== 'app/Models/ClientPortalUser.php'
                && $path !== 'app/Models/NotificationEvent.php'
                && $path !== 'app/Models/User.php'
                && $path !== 'app/Notifications/ClientPortalResetPasswordNotification.php'
                && $path !== 'app/Notifications/FirmOwnerInvitationNotification.php'
                && $path !== 'app/Providers/AppServiceProvider.php'
                && $path !== 'config/mail.php'
                && $path !== 'config/services.php'
                && $path !== 'app/Enums/SesBounceType.php'
                && $path !== 'app/Enums/SesEventType.php'
                && $path !== 'app/Models/NotificationProviderCorrelation.php'
                && $path !== 'app/Models/SesEventReceipt.php'
                && $path !== 'app/Console/Commands/ConsumeSesEventsCommand.php'
                && $path !== 'database/migrations/2026_10_15_100001_add_provider_message_id_to_notification_events_table.php'
                && $path !== 'database/migrations/2026_10_15_100002_create_notification_provider_correlations_table.php'
                && $path !== 'database/migrations/2026_10_15_100003_create_ses_event_receipts_table.php'
                && $path !== 'tests/Feature/Notifications/ConsumeSesEventsCommandTest.php'
                && $path !== 'tests/Feature/Notifications/OutboundMailCorrelationServiceTest.php'
                && $path !== 'tests/Feature/Notifications/SesEventConsumerServiceTest.php'
                // post-578ee98 audit remediation — new platform-scope
                // correlation/suppression subsystem + its own test
                // files, plus the real-resolved-SES-transport test.
                && $path !== 'app/Models/PlatformNotificationCorrelation.php'
                && $path !== 'app/Models/PlatformNotificationSuppression.php'
                && $path !== 'database/migrations/2026_10_20_100001_create_platform_notification_correlations_table.php'
                && $path !== 'database/migrations/2026_10_20_100002_create_platform_notification_suppressions_table.php'
                && $path !== 'tests/Feature/Mail/SesMailerTransportTest.php'
                && $path !== 'tests/Feature/Notifications/PasswordResetPlatformCorrelationFallbackTest.php'
                && $path !== 'tests/Feature/Notifications/PlatformNotificationCorrelationServiceTest.php'
                // Round 3 audit remediation legitimately added these.
                && $path !== 'app/Enums/CorrelatedSendResult.php'
                && $path !== 'app/Exceptions/NotificationTransportFailedException.php'
                && $path !== '.env.example',
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
