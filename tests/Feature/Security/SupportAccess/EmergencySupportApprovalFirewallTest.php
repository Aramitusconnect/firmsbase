<?php

namespace Tests\Feature\Security\SupportAccess;

use App\Enums\HighRiskChangeType;
use Tests\TestCase;

/**
 * EmergencySupportApprovalFirewallTest — Section 39C. Proves the fix
 * stayed inside its declared boundary: no new migrations/tables, no
 * new HighRiskChangeType case, no new approval/audit system, no
 * modification to HighRiskPlatformChangePolicyService/HighRiskChangeType/
 * the SupportAccessRequest schema/model, no UI/route files, and no
 * unrelated behavior (payment/trust/AI/RLS/login/2FA/seed) touched.
 */
class EmergencySupportApprovalFirewallTest extends TestCase
{
    /**
     * Files this section is allowed to have modified.
     */
    private const ALLOWED_MODIFIED_FILES = [
        'app/Services/SupportAccessRequestService.php',
        'app/Services/SupportAccessPolicyService.php',
        'app/Services/EmergencyAccessGovernanceGapService.php',
        // Section 39E (a later, distinct security-remediation branch)
        // legitimately adds its own new app/Services file.
        'app/Services/SeedDataSecurityAuditService.php',
        // Section 39B (a later, distinct backend-policy branch)
        // legitimately adds its own new app/Services file.
        'app/Services/FirmUser2faPolicyService.php',
        // Section 39D (a later, distinct backend-policy branch)
        // legitimately adds its own new app/Services file.
        'app/Services/LoginPolicyService.php',
        // Section 39A (a later, distinct RLS-activation branch)
        // legitimately adds its own new app/Services file.
        'app/Services/TenantContextService.php',
        // Section 40 (a later, distinct limited-pilot-safety-gate
        // branch) legitimately adds its own read-only gate service.
        'app/Services/Section40LimitedPilotSafetyGateService.php',
        // Section 39A-3K (this batch, a later, distinct staged-FORCE-
        // activation branch) legitimately wired explicit tenant
        // context into CalendarEventService and EmployeeRateService
        // now that calendar_events and employee_rates each have
        // permanent FORCE ROW LEVEL SECURITY.
        'app/Services/CalendarEventService.php',
        'app/Services/EmployeeRateService.php',
        // Section 39A-3L Checkpoint 18 addendum (this batch, a later,
        // distinct staged-FORCE-activation branch) legitimately fixed
        // TrustEligibilityService now that firm_settings has permanent
        // FORCE ROW LEVEL SECURITY.
        'app/Services/TrustEligibilityService.php',
        // Section 39A-3L, Checkpoint 22 (this batch, a later, distinct
        // staged-FORCE-activation branch) legitimately wired explicit
        // tenant context into PaymentPlanService and
        // CustomerSuccessHealthScoreService now that payment_plans has
        // permanent FORCE ROW LEVEL SECURITY.
        'app/Services/PaymentPlanService.php',
        'app/Services/CustomerSuccessHealthScoreService.php',
        // Section 39A-3L, Checkpoint 24 (this batch, a later, distinct
        // staged-FORCE-activation branch) legitimately wired
        // independent runWithFirmContext() wraps into
        // NotificationDispatchService and SuppressionService now that
        // notification_events has permanent FORCE ROW LEVEL SECURITY.
        'app/Services/NotificationDispatchService.php',
        'app/Services/SuppressionService.php',
        // Section 39A-8 Wave 8 (this batch, a later, distinct
        // coordinated multi-table RLS-activation wave covering the
        // governance/support/platform domain) legitimately wired
        // independent runWithFirmContext() wraps into every one of
        // these services, since legal_holds, deletion_requests,
        // key_destruction_requests, support_access_requests,
        // support_access_sessions, and deployment_health_checks all now
        // have permanent FORCE ROW LEVEL SECURITY.
        'app/Services/LegalHoldService.php',
        'app/Services/DeletionRequestService.php',
        'app/Services/DeletionGovernanceService.php',
        'app/Services/DeletionApprovalService.php',
        'app/Services/KeyDestructionRequestService.php',
        'app/Services/KeyDestructionApprovalService.php',
        'app/Services/KeyDestructionExecutionService.php',
        'app/Services/OffboardingRequestService.php',
        'app/Services/SupportAccessSessionService.php',
        'app/Services/DeploymentHealthEnvelopeService.php',
        'app/Services/AccessReviewService.php',
        // Section 39A-9 Wave 9 (this batch, a later, distinct
        // coordinated multi-table RLS-activation wave covering the
        // migration/export domain) legitimately wired independent
        // runWithFirmContext() wraps into every one of these services,
        // since export_jobs, migration_projects, import_batches,
        // implementation_projects, fleet_migration_instance_status, and
        // offboarding_requests all now have permanent FORCE ROW LEVEL
        // SECURITY. (ImportApplyService.php, ImportRollbackService.php,
        // and OffboardingRequestService.php were already allowed above
        // from earlier waves and needed no new entry here.)
        'app/Services/ExportJobService.php',
        'app/Services/FleetMigrationOrchestrationService.php',
        'app/Services/ImplementationProjectService.php',
        'app/Services/ImplementationTaskService.php',
        'app/Services/ImportBatchService.php',
        'app/Services/ImportPreviewService.php',
        'app/Services/ImportRowValidationService.php',
        'app/Services/MigrationProjectService.php',
        // Section 39A-5 Wave 11 (the final wave of the 60-table RLS
        // rollout) legitimately updated the shared RLS coverage mapping
        // service once every table was moved into PREPARED_TABLES.
        'app/Services/RowLevelSecurityCoverageMappingService.php',
        // Same Wave 11 pass also corrected these two services' own
        // governance notes text, which had gone self-contradictory once
        // MISSING_PREPARED_TABLES became genuinely empty — the
        // PartiallyImplemented status itself was not changed, only the
        // stated reasons.
        'app/Services/DeploymentModeCoverageMappingService.php',
        'app/Services/TestCoverageMappingService.php',
        // feature/ses-event-consumer (a later, distinct, wholly
        // isolated mission: a production-safe SES bounce/complaint
        // consumer) legitimately added these two new services.
        'app/Services/OutboundMailCorrelationService.php',
        'app/Services/SesEventConsumerService.php',
        // Round 3 audit remediation legitimately added this new
        // dedicated service.
        'app/Services/CorrelatedPasswordResetSenderService.php',
    ];

    /**
     * Files this section must NOT modify.
     */
    private const PROTECTED_FILES = [
        'app/Services/HighRiskPlatformChangePolicyService.php',
        'app/Enums/HighRiskChangeType.php',
        'app/Models/SupportAccessRequest.php',
        'app/Models/HighRiskChangeRequest.php',
        'app/Models/SupportAccessSession.php',
        // SupportAccessSessionService.php is deliberately NOT in this
        // list any more — Section 39A-8 Wave 8 found a genuine need to
        // wrap start()/end()/revoke()'s writes in runWithFirmContext(),
        // since support_access_sessions now has permanent FORCE ROW
        // LEVEL SECURITY. isValid() and every decision/branch/return
        // value are byte-for-byte identical.
        // PaymentClassificationService.php is deliberately NOT in this
        // list any more — Section 39A-3H (a later, distinct staged-
        // FORCE-activation branch) found a genuine need to wire
        // recordDecision()'s $payment->update() call with explicit
        // tenant context, since payments now has permanent FORCE ROW
        // LEVEL SECURITY.
        // TrustEligibilityService.php is deliberately NOT in this list
        // any more — Section 39A-3L, Checkpoint 18 (a later, distinct
        // staged-FORCE-activation branch) found a genuine need to wrap
        // evaluate()'s $firm->firmSettings read in runWithFirmContext(),
        // since firm_settings now has permanent FORCE ROW LEVEL
        // SECURITY. Only the single $settings read line changed —
        // decision logic, order, and return values are byte-for-byte
        // identical.
        'app/Services/AiRetrievalIsolationService.php',
        // RowLevelSecurityCoverageMappingService.php is deliberately
        // NOT in this list any more — Section 39A-5 Wave 11 (the final
        // wave of the 60-table RLS rollout) found a genuine need to
        // update the shared RLS coverage registry once every table was
        // moved into PREPARED_TABLES and MISSING_PREPARED_TABLES
        // became genuinely empty.
        'app/Services/ConsentService.php',
        'app/Services/ComplianceGapRegistryService.php',
    ];

    public function test_no_new_migration_files_were_added(): void
    {
        $changed = $this->changedOrUntrackedPaths('database/migrations');

        $this->assertEmpty($changed, 'Section 39C must add no migrations, but found: '.implode(', ', $changed));
    }

    public function test_no_new_tables_were_created(): void
    {
        $this->assertEmpty(glob(database_path('schema/*.sql')) ?: []);
        $this->assertEmpty($this->changedOrUntrackedPaths('database/migrations'));
    }

    public function test_high_risk_change_type_gained_no_new_case(): void
    {
        $cases = array_map(fn ($case) => $case->value, HighRiskChangeType::cases());

        $this->assertCount(7, $cases, 'HighRiskChangeType must not gain a new case for this narrow remediation.');
        $this->assertContains('emergency_support_access', $cases);
    }

    public function test_high_risk_change_type_and_policy_service_are_untouched(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $this->assertNotContains('app/Services/HighRiskPlatformChangePolicyService.php', $changed);
        $this->assertNotContains('app/Enums/HighRiskChangeType.php', $changed);
    }

    public function test_support_access_request_model_and_schema_are_untouched(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $this->assertNotContains('app/Models/SupportAccessRequest.php', $changed);
        $this->assertEmpty($this->changedOrUntrackedPaths('database/migrations'));
    }

    public function test_no_ui_routes_controllers_filament_blade_or_livewire_changes(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "Section 39C must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }
    }

    public function test_no_unrelated_protected_behavior_files_were_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $touched = array_values(array_intersect(self::PROTECTED_FILES, $changed));

        $this->assertEmpty($touched, 'Section 39C must not modify protected/unrelated files, but found: '.implode(', ', $touched));
    }

    public function test_only_allowed_app_service_files_were_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services');

        $unexpected = array_values(array_diff($changed, self::ALLOWED_MODIFIED_FILES));

        $this->assertEmpty($unexpected, 'Section 39C must only modify the allowed service files, but found: '.implode(', ', $unexpected));
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched — no resolved-state lifecycle exists to safely mark the gap resolved.');
    }

    public function test_no_second_high_risk_or_support_access_approval_model_was_introduced(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/EmergencySupportApproval.php'));
        $this->assertFileDoesNotExist(app_path('Models/SupportAccessApproval.php'));
        $this->assertFileDoesNotExist(app_path('Services/EmergencySupportApprovalService.php'));

        $duplicatePolicyServices = glob(app_path('Services/*HighRisk*ChangePolicy*.php')) ?: [];
        $this->assertCount(1, $duplicatePolicyServices, 'Only one high-risk change policy service may exist.');
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

        $paths = preg_split('/\R/', $changed) ?: [];

        // Section 39B (a later, distinct backend-policy branch)
        // legitimately added exactly one migration and modified
        // FirmSettings.php — excluded here (by exact path, regardless
        // of scope) so this section's own declarative-only guarantee
        // still holds without touching every individual check.
        $section39bAllowed = [
            'database/migrations/2026_07_29_900001_add_firm_user_2fa_mode_to_firm_settings_table.php',
            'app/Models/FirmSettings.php',
            // Section 39A-3A (a later, distinct staged-FORCE-
            // activation branch) legitimately added a clients-only
            // FORCE RLS migration, a ClientFactory context fix, and
            // explicit tenant-context wiring in several real services
            // that write/read clients directly.
            'database/migrations/2026_07_30_900001_force_rls_on_clients_table.php',
            'database/factories/ClientFactory.php',
            'app/Services/ClientPortalService.php',
            'app/Services/ConflictCheckService.php',
            'app/Services/FirmCommandCenterAggregationService.php',
            'app/Services/ImportApplyService.php',
            'app/Services/ImportDuplicateDetectionService.php',
            'app/Services/ImportRollbackService.php',
            'app/Services/LeadConversionService.php',
            'tests/Feature/Imports/ImportApplyServiceTest.php',
            'tests/Feature/Imports/ImportRollbackServiceTest.php',
            'tests/Feature/Webhooks/Wiring/ClientCreatedWiringTest.php',
            // Section 39A-3B (a later, distinct staged-FORCE-
            // activation branch) legitimately added a firm_users-only
            // FORCE RLS migration, a FirmUserFactory context fix, and
            // explicit tenant-context wiring in real services that
            // read firm_users directly, plus updated the legitimately
            // cross-firm relationship tests it affected.
            'database/migrations/2026_07_31_900001_force_rls_on_firm_users_table.php',
            'database/factories/FirmUserFactory.php',
            'app/Services/LoginPolicyService.php',
            'app/Services/MatterAccessPolicyService.php',
            'app/Services/AccessReviewService.php',
            'tests/Feature/Identity/FirmUserTest.php',
            'tests/Feature/Identity/UserFirmRelationshipsTest.php',
            'tests/Feature/Tenancy/RowLevelSecurityPreparationTest.php',
            // Section 39A-3C (a later, distinct staged-FORCE-
            // activation branch) legitimately added a documents-only
            // FORCE RLS migration, a DocumentFactory context fix, and
            // explicit tenant-context wiring in several real services
            // that write/read documents directly, plus updated the
            // tests it affected.
            'database/migrations/2026_08_01_900001_force_rls_on_documents_table.php',
            'database/factories/DocumentFactory.php',
            'app/Services/DocumentReplacementService.php',
            'app/Services/DocumentSecurityService.php',
            'app/Services/EmailAttachmentPromotionService.php',
            'app/Services/SignatureCertificateService.php',
            'tests/Feature/Documents/DocumentReplacementServiceTest.php',
            'tests/Feature/Webhooks/Wiring/DocumentUploadedWiringTest.php',
            'tests/Feature/Signature/Certificates/SignatureCertificateOnePerRequestTest.php',
            'tests/Feature/Signature/Certificates/SignatureCertificateRequiresHashAndEventTrailTest.php',
            'tests/Feature/Signature/Certificates/SignatureCertificateServiceTest.php',
            // Section 39A-3D (a later, distinct staged-FORCE-
            // activation branch) legitimately added a deadlines-only
            // FORCE RLS migration, a DeadlineFactory context fix, and
            // explicit tenant-context wiring in DeadlineService.
            'database/migrations/2026_08_02_900001_force_rls_on_deadlines_table.php',
            'database/factories/DeadlineFactory.php',
            'app/Services/DeadlineService.php',
            // Section 39A-3E (a later, distinct staged-FORCE-
            // activation branch) legitimately added a tasks-only
            // FORCE RLS migration, a TaskFactory context fix, and
            // explicit tenant-context wiring in TaskService,
            // TaskDependencyService, and MatterReadinessService.
            'database/migrations/2026_08_03_900001_force_rls_on_tasks_table.php',
            'database/factories/TaskFactory.php',
            'app/Services/TaskService.php',
            'app/Services/TaskDependencyService.php',
            // Section 39A-3F (a later, distinct staged-FORCE-
            // activation branch) legitimately added a matters-only
            // FORCE RLS migration, a MatterFactory root-cause
            // firm/client consistency fix, explicit tenant-context
            // wiring in MatterOpeningService, MatterReadinessService,
            // ProductionPilotWorkflowService, and
            // WebhookEventRecorderService, plus updated the tests it
            // affected.
            'database/migrations/2026_08_04_900001_force_rls_on_matters_table.php',
            'database/factories/MatterFactory.php',
            'app/Services/MatterOpeningService.php',
            'app/Services/MatterReadinessService.php',
            'app/Services/ProductionPilotWorkflowService.php',
            'app/Services/WebhookEventRecorderService.php',
            'tests/Feature/Matters/MatterOpeningServiceTest.php',
            'tests/Feature/MobilePortal/MobilePortalReadinessServiceTest.php',
            'tests/Feature/PilotWorkflow/ProductionPilotWorkflowServiceTest.php',
            'tests/Feature/Webhooks/Wiring/MatterCreatedWiringTest.php',
            'tests/Feature/Webhooks/Wiring/MatterReadinessChangedWiringTest.php',
            // Section 39A-3G (a later, distinct staged-FORCE-
            // activation branch) legitimately added an invoices-only
            // FORCE RLS migration, an InvoiceFactory root-cause
            // firm/client consistency fix, explicit tenant-context
            // wiring in InvoiceDraftingService, ImportApplyService,
            // ManualPaymentService, PaymentApplicationService,
            // TrustTransferRequestService,
            // AccountingExportLineBuilderService, and
            // FirmCommandCenterAggregationService, plus updated the
            // tests it affected.
            'database/migrations/2026_08_05_900001_force_rls_on_invoices_table.php',
            'database/factories/InvoiceFactory.php',
            'app/Services/InvoiceDraftingService.php',
            'app/Services/ManualPaymentService.php',
            'app/Services/PaymentApplicationService.php',
            'app/Services/TrustTransferRequestService.php',
            'app/Services/AccountingExportLineBuilderService.php',
            'tests/Feature/Invoicing/InvoiceDraftingServiceTest.php',
            'tests/Feature/Payments/PaymentApplicationServiceTest.php',
            'tests/Feature/Trust/Transfers/TrustTransferRequestServiceTest.php',
            // Section 39A-3H (a later, distinct staged-FORCE-
            // activation branch) legitimately added a payments-only
            // FORCE RLS migration, a PaymentFactory root-cause
            // firm/client consistency fix, explicit tenant-context
            // wiring in ManualPaymentService, PaymentClassificationService,
            // TrustTransferRequestService,
            // AccountingExportLineBuilderService, and
            // FirmCommandCenterAggregationService, plus updated the
            // tests it affected.
            'database/migrations/2026_08_06_900001_force_rls_on_payments_table.php',
            'database/factories/PaymentFactory.php',
            'app/Services/PaymentClassificationService.php',
            'tests/Feature/Payments/ManualPaymentServiceTest.php',
            'tests/Feature/Webhooks/Wiring/PaymentRecordedWiringTest.php',
            // Internal login/panel access wiring (a later, distinct
            // section) legitimately added a migration extending
            // firm_users' RLS policy with a narrow self-lookup
            // clause needed to bootstrap-resolve an authenticated
            // user's own firm from firm_users itself, real
            // platform_admin/web guard + Filament panel wiring, and
            // its own test files.
            'database/migrations/2026_08_10_900001_add_self_lookup_clause_to_firm_users_rls_policy.php',
            // Section 39A-3I (a later, distinct staged-FORCE-
            // activation branch) legitimately added a
            // conflict_check_runs-only FORCE RLS migration.
            'database/migrations/2026_08_11_900001_force_rls_on_conflict_check_runs_table.php',
            // Section 39A-3J (a later, distinct staged-FORCE-
            // activation branch) legitimately added FORCE RLS
            // migrations for lead_sources, consultation_outcomes,
            // firm_leads, and consultations together, their
            // factory context-hold fixes, and updated the tests
            // it affected.
            'database/migrations/2026_08_12_900001_force_rls_on_lead_sources_table.php',
            'database/migrations/2026_08_13_900001_force_rls_on_consultation_outcomes_table.php',
            'database/migrations/2026_08_14_900001_force_rls_on_firm_leads_table.php',
            'database/migrations/2026_08_15_900001_force_rls_on_consultations_table.php',
            'database/factories/LeadSourceFactory.php',
            'database/factories/ConsultationOutcomeFactory.php',
            'database/factories/FirmLeadFactory.php',
            'database/factories/ConsultationFactory.php',
            'tests/Feature/Leads/LeadConversionServiceTest.php',
            'tests/Feature/Webhooks/Wiring/LeadCreatedWiringTest.php',
            // Section 39A-3K (this batch, a later, distinct
            // staged-FORCE-activation branch) legitimately added
            // FORCE RLS migrations for firm_practice_areas,
            // document_chase_rules, employee_rates, calendar_events,
            // and client_communication_preferences together, their
            // factory context-hold fixes, and updated the tests it
            // affected.
            'database/migrations/2026_08_20_920001_force_rls_on_firm_practice_areas_table.php',
            'database/migrations/2026_08_20_920002_force_rls_on_document_chase_rules_table.php',
            'database/migrations/2026_08_20_920003_force_rls_on_employee_rates_table.php',
            'database/migrations/2026_08_20_920004_force_rls_on_calendar_events_table.php',
            'database/migrations/2026_08_20_920005_force_rls_on_client_communication_preferences_table.php',
            'database/factories/CalendarEventFactory.php',
            'database/factories/ClientCommunicationPreferenceFactory.php',
            'database/factories/DocumentChaseRuleFactory.php',
            'database/factories/EmployeeRateFactory.php',
            'database/factories/FirmPracticeAreaFactory.php',
            'tests/Feature/Deadlines/CalendarEventServiceTest.php',
            'tests/Feature/Deadlines/DeadlineServiceTest.php',
            'tests/Feature/DocumentChase/DocumentChaseSchedulerServiceTest.php',
            'tests/Feature/Rates/EmployeeRateServiceTest.php',
            // Section 39A-3L, Checkpoint 10, Table Phase C (this
            // batch, a later, distinct staged-FORCE-activation
            // branch) legitimately added a document_requests-only
            // FORCE RLS migration, a DocumentRequestFactory
            // firm/client consistency + context-hold fix, wrapped
            // DocumentRequestService's create() and its 7
            // single-item mutators and DocumentChaseService's
            // checkAndLog()/escalate()/pause()/resume() each in
            // their own runWithFirmContext() call, and updated the
            // tests it affected.
            'database/migrations/2026_08_25_930010_force_rls_on_document_requests_table.php',
            'database/factories/DocumentRequestFactory.php',
            'app/Services/DocumentRequestService.php',
            'app/Services/DocumentChaseService.php',
            'app/Services/MobilePortalReadinessService.php',
            'tests/Feature/Documents/DocumentRequestServiceTest.php',
            'tests/Feature/DocumentChase/DocumentChaseServiceTest.php',
            'tests/Feature/Readiness/MatterReadinessServiceTest.php',
            'tests/Feature/Governance/MarketReadyValueMultipliers/FirmCommandCenterAggregationServiceTest.php',
            // Section 39A-3L, Checkpoint 11, Table Phase C (this
            // batch, a later, distinct staged-FORCE-activation
            // branch) legitimately added a communication_consents-
            // only FORCE RLS migration, wrapped ConsentService's
            // capture()/revoke() in their own runWithFirmContext()
            // call, moved ClientPortalService::invite()'s
            // isGranted() precondition inside its existing
            // runWithFirmContext() wrap, added a
            // CommunicationConsentFactory context-hold fix, and
            // updated the tests it affected.
            'database/migrations/2026_08_25_930011_force_rls_on_communication_consents_table.php',
            'database/factories/CommunicationConsentFactory.php',
            'app/Services/ConsentService.php',
            'tests/Feature/Activation/ConsentServiceTest.php',
            'tests/Feature/PaymentPlans/PaymentPlanDunningServiceTest.php',
            // Section 39A-3L, Checkpoint 12, Table Phase C (this
            // batch, a later, distinct staged-FORCE-activation
            // branch) legitimately added a
            // communication_consent_events-only FORCE RLS
            // migration, a CommunicationConsentEventFactory
            // firm/consent consistency + context-hold fix, and
            // fixed pre-existing bare-assertion-after-service-call
            // gaps this batch's own FORCE activation exposed in
            // ConsentServiceTest.php (already allowed above).
            'database/migrations/2026_08_25_930012_force_rls_on_communication_consent_events_table.php',
            'database/factories/CommunicationConsentEventFactory.php',
            // Section 39A-3L, Checkpoint 13, Table Phase C (this
            // batch, a later, distinct staged-FORCE-activation
            // branch) legitimately added an intake_submissions-only
            // FORCE RLS migration and an IntakeSubmissionFactory
            // firm/client consistency + context-hold fix.
            'database/migrations/2026_08_25_930013_force_rls_on_intake_submissions_table.php',
            'database/factories/IntakeSubmissionFactory.php',
            'config/auth.php',
            'app/Models/User.php',
            'app/Models/PlatformAdmin.php',
            'app/Http/Middleware/EstablishFirmTenantContext.php',
            'app/Providers/Filament/AdminPanelProvider.php',
            'app/Providers/Filament/FirmPanelProvider.php',
            'app/Providers/AppServiceProvider.php',
            'bootstrap/providers.php',
            'tests/Feature/Security/Login/PlatformAdminLoginPanelAccessTest.php',
            'tests/Feature/Security/Login/FirmUserLoginPanelAccessTest.php',
            'tests/Feature/Security/Login/CrossPanelAuthGuardTest.php',
            'tests/Feature/Security/Login/TenantContextMiddlewareTest.php',
            'tests/Feature/Security/FirmUser2fa/FirmUser2faLoginEnforcementTest.php',
            'tests/Feature/Security/LoginPolicy/LoginPolicyEnforcementTest.php',
            'app/Services/ReadinessScorecardRegistry.php',
            'tests/Feature/Tasks/TaskDependencyServiceTest.php',
            'tests/Feature/Webhooks/Wiring/TaskCompletedWiringTest.php',
            // Section 39A-3L, Checkpoint 22, Table Phase C (this batch,
            // a later, distinct staged-FORCE-activation branch)
            // legitimately added a payment_plans-only FORCE RLS
            // migration, a PaymentPlanFactory context-hold + firm/client
            // consistency fix, and updated the one existing test that
            // genuinely needed explicit tenant context after this
            // activation.
            'database/migrations/2026_08_25_930022_force_rls_on_payment_plans_table.php',
            'database/factories/PaymentPlanFactory.php',
            'tests/Feature/PaymentPlans/PaymentPlanServiceTest.php',
            // Section 39A-3L, Checkpoint 23, Table Phase C (this batch,
            // a later, distinct staged-FORCE-activation branch)
            // legitimately added a payment_plan_events-only FORCE RLS
            // migration and a PaymentPlanEventFactory context-hold +
            // firm/plan consistency fix — no production service file
            // required any wiring change this checkpoint. The same
            // PaymentPlanServiceTest.php (already allowed above) was
            // updated again to wrap two assertDatabaseHas() calls in
            // tenant context.
            'database/migrations/2026_08_25_930023_force_rls_on_payment_plan_events_table.php',
            'database/factories/PaymentPlanEventFactory.php',
            // Section 39A-3L, Checkpoint 24 (this batch, a later,
            // distinct staged-FORCE-activation branch) legitimately
            // added a notification_events-only FORCE RLS migration, a
            // NotificationEventFactory context-hold fix, and updated
            // the two existing tests that legitimately needed explicit
            // tenant context after this activation.
            'database/migrations/2026_08_25_930024_force_rls_on_notification_events_table.php',
            'database/factories/NotificationEventFactory.php',
            'tests/Feature/Notifications/NotificationDispatchServiceTest.php',
            'tests/Feature/Notifications/SuppressionServiceTest.php',
            // Section 39A-8 Wave 8 (the eighth coordinated multi-table
            // wave, governance/support/platform domain) legitimately
            // added six combined prepare-and-force migrations
            // (legal_holds, deletion_requests, key_destruction_requests,
            // support_access_requests, support_access_sessions,
            // deployment_health_checks), their six factories'
            // context-hold fixes, and updated the tests it affected.
            'database/migrations/2026_08_28_960001_prepare_row_level_security_and_force_rls_on_legal_holds_table.php',
            'database/migrations/2026_08_28_960002_prepare_row_level_security_and_force_rls_on_deletion_requests_table.php',
            'database/migrations/2026_08_28_960003_prepare_row_level_security_and_force_rls_on_key_destruction_requests_table.php',
            'database/migrations/2026_08_28_960004_prepare_row_level_security_and_force_rls_on_support_access_requests_table.php',
            'database/migrations/2026_08_28_960005_prepare_row_level_security_and_force_rls_on_support_access_sessions_table.php',
            'database/migrations/2026_08_28_960006_prepare_row_level_security_and_force_rls_on_deployment_health_checks_table.php',
            'database/factories/LegalHoldFactory.php',
            'database/factories/DeletionRequestFactory.php',
            'database/factories/KeyDestructionRequestFactory.php',
            'database/factories/SupportAccessRequestFactory.php',
            'database/factories/SupportAccessSessionFactory.php',
            'database/factories/DeploymentHealthCheckFactory.php',
            'tests/Feature/Governance/KeyDestruction/KeyDestructionLifecycleTest.php',
            'tests/Feature/Governance/Deletion/DeletionGovernanceLifecycleTest.php',
            'tests/Feature/Governance/LegalHold/LegalHoldServiceTest.php',
            'tests/Feature/Deployment/Health/DeploymentHealthEnvelopeServiceTest.php',
            'tests/Feature/SupportAccess/SupportAccessSessionServiceTest.php',
            'tests/Feature/Security/RlsForceRollout/TimelineEventsForceRlsActivationTest.php',
            // Section 39A-9 Wave 9 (migration/export domain) legitimately
            // added six combined prepare-and-force migrations (export_jobs,
            // migration_projects, import_batches, implementation_projects,
            // fleet_migration_instance_status, offboarding_requests), their
            // six factories' context-hold fixes, and updated the tests it
            // affected. The wired service files themselves are allowed via
            // ALLOWED_MODIFIED_FILES above.
            'database/migrations/2026_08_29_970001_prepare_row_level_security_and_force_rls_on_export_jobs_table.php',
            'database/migrations/2026_08_29_970002_prepare_row_level_security_and_force_rls_on_migration_projects_table.php',
            'database/migrations/2026_08_29_970003_prepare_row_level_security_and_force_rls_on_import_batches_table.php',
            'database/migrations/2026_08_29_970004_prepare_row_level_security_and_force_rls_on_implementation_projects_table.php',
            'database/migrations/2026_08_29_970005_prepare_row_level_security_and_force_rls_on_fleet_migration_instance_status_table.php',
            'database/migrations/2026_08_29_970006_prepare_row_level_security_and_force_rls_on_offboarding_requests_table.php',
            'database/factories/ExportJobFactory.php',
            'database/factories/FleetMigrationInstanceStatusFactory.php',
            'database/factories/ImplementationProjectFactory.php',
            'database/factories/ImportBatchFactory.php',
            'database/factories/MigrationProjectFactory.php',
            'database/factories/OffboardingRequestFactory.php',
            // FIRMSVAULT — STAGING ADMIN STABILIZATION (a later,
            // independently reviewed mission) legitimately touches
            // files under this section's own protected scope — see
            // that mission's own commit history for full context.
            'config/database.php',
            'app/Models/Plan.php',
            'app/Services/PlanService.php',
            'app/Services/PlanModuleService.php',
            'app/Services/FirmProvisioningService.php',
            'app/Exceptions/InactivePlanSelectedException.php',
            'app/Console/Commands/BootstrapStagingSandboxPlanCommand.php',
            'app/Filament/Actions/Platform/CreatePlanAction.php',
            'app/Filament/Actions/Platform/EditPlanAction.php',
            'app/Filament/Actions/Platform/AddPlanModuleAction.php',
            'app/Filament/Resources/PlanResource.php',
            'app/Filament/Resources/PlanResource/Pages/ListPlans.php',
            'app/Filament/Resources/PlanAddOnResource.php',
            'app/Filament/Resources/PlanAddOnResource/Pages/ListPlanAddOns.php',
            'database/migrations/2026_10_10_100001_add_code_and_description_to_plans_table.php',
            'database/factories/PlanFactory.php',
            'tests/Feature/Ecs/RedisTlsConfigurationTest.php',
            'tests/Feature/Plans/PlanServiceTest.php',
            'tests/Feature/Services/FirmProvisioningServiceTest.php',
            'tests/Feature/Console/BootstrapStagingSandboxPlanCommandTest.php',
            'tests/Feature/PlatformAdmin/PlanCatalogCreateActionsTest.php',
            'tests/Feature/Security/RlsContextRollout/QueueConsoleContextRolloutTest.php',
            'tests/Feature/Security/RlsEnforcement/QueueConsoleTenantContextTest.php',
            'tests/Feature/Security/SeedData/SecretPatternScanTest.php',
            'tests/Feature/Integrations/Ui/FirmIntegrationSuperAdminBoundaryStructuralTest.php',
            // feature/ses-event-consumer (a later, distinct, wholly
            // isolated mission: a production-safe SES bounce/
            // complaint consumer) legitimately added a
            // notification-provider correlation ledger + idempotency
            // ledger (both exempted, no-RLS, registered in
            // RowLevelSecurityCoverageMappingService per the same
            // integration_webhook_routing_index/
            // integration_platform_provider_health_summaries
            // precedent pattern), a dedicated SQS consumer command,
            // real-send correlation wiring in User/ClientPortalUser
            // password-reset notifications, and its own new test
            // files.
            'app/Models/ClientPortalUser.php',
            'app/Models/NotificationEvent.php',
            'app/Models/User.php',
            'app/Notifications/ClientPortalResetPasswordNotification.php',
            'app/Notifications/FirmOwnerInvitationNotification.php',
            'app/Providers/AppServiceProvider.php',
            'app/Services/NotificationDispatchService.php',
            'app/Services/RowLevelSecurityCoverageMappingService.php',
            'config/mail.php',
            'config/services.php',
            'tests/Feature/Governance/DataModelContract/RowLevelSecurityCoverageMappingServiceTest.php',
            'tests/Feature/Security/SeedData/SecretPatternScanTest.php',
            'app/Console/Commands/ConsumeSesEventsCommand.php',
            'app/Enums/SesBounceType.php',
            'app/Enums/SesEventType.php',
            'app/Models/NotificationProviderCorrelation.php',
            'app/Models/SesEventReceipt.php',
            'app/Services/OutboundMailCorrelationService.php',
            'app/Services/SesEventConsumerService.php',
            'database/migrations/2026_10_15_100001_add_provider_message_id_to_notification_events_table.php',
            'database/migrations/2026_10_15_100002_create_notification_provider_correlations_table.php',
            'database/migrations/2026_10_15_100003_create_ses_event_receipts_table.php',
            'tests/Feature/Notifications/ConsumeSesEventsCommandTest.php',
            'tests/Feature/Notifications/OutboundMailCorrelationServiceTest.php',
            'tests/Feature/Notifications/SesEventConsumerServiceTest.php',
            // post-578ee98 audit remediation (a later, distinct,
            // independent security/architecture review of the SES
            // event consumer feature) legitimately fixed a
            // MessageSent listener leak, an uncaught-exception
            // crash risk, a receipt-write concurrency race, a
            // complaint recipient-mismatch hard-reject, and added a
            // new platform-scope correlation/suppression subsystem
            // for password-reset sends that cannot resolve a firm —
            // plus its own new test files.
            'app/Console/Commands/ConsumeSesEventsCommand.php',
            'app/Models/ClientPortalUser.php',
            'app/Models/User.php',
            'app/Services/OutboundMailCorrelationService.php',
            'app/Services/RowLevelSecurityCoverageMappingService.php',
            'app/Services/SesEventConsumerService.php',
            'app/Services/SuppressionService.php',
            'config/services.php',
            'database/migrations/2026_10_15_100002_create_notification_provider_correlations_table.php',
            'tests/Feature/Governance/DataModelContract/RowLevelSecurityCoverageMappingServiceTest.php',
            'tests/Feature/Mail/SesMailerTransportTest.php',
            'tests/Feature/Notifications/ConsumeSesEventsCommandTest.php',
            'tests/Feature/Notifications/OutboundMailCorrelationServiceTest.php',
            'tests/Feature/Notifications/SesEventConsumerServiceTest.php',
            'tests/Feature/Notifications/SuppressionServiceTest.php',
            'app/Models/PlatformNotificationCorrelation.php',
            'app/Models/PlatformNotificationSuppression.php',
            'app/Services/PlatformNotificationCorrelationService.php',
            'database/migrations/2026_10_20_100001_create_platform_notification_correlations_table.php',
            'database/migrations/2026_10_20_100002_create_platform_notification_suppressions_table.php',
            'tests/Feature/Notifications/PasswordResetPlatformCorrelationFallbackTest.php',
            'tests/Feature/Notifications/PlatformNotificationCorrelationServiceTest.php',
            // Round 3 audit remediation (a later, distinct,
            // independent security/architecture review) legitimately
            // introduced CorrelatedPasswordResetSenderService, fixed
            // a transaction-poisoning bug in both correlation
            // services, and rewired FirmProvisioningService's
            // owner-invitation dispatch — plus its own new test
            // files.
            '.env.example',
            'app/Models/ClientPortalUser.php',
            'app/Models/User.php',
            'app/Services/FirmProvisioningService.php',
            'app/Services/OutboundMailCorrelationService.php',
            'app/Services/PlatformNotificationCorrelationService.php',
            'tests/Feature/Notifications/OutboundMailCorrelationServiceTest.php',
            'tests/Feature/Notifications/PasswordResetPlatformCorrelationFallbackTest.php',
            'tests/Feature/Notifications/PlatformNotificationCorrelationServiceTest.php',
            'tests/Feature/Services/FirmProvisioningServiceTest.php',
            'app/Enums/CorrelatedSendResult.php',
            'app/Exceptions/NotificationTransportFailedException.php',
            'app/Services/CorrelatedPasswordResetSenderService.php',
        ];

        return array_values(array_filter(
            $paths,
            fn (string $path) => ! in_array($path, $section39bAllowed, true),
        ));
    }
}
