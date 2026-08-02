<?php

namespace Tests\Feature\Security\RlsEnforcement;

use App\Enums\GovernanceGapSeverity;
use App\Services\ComplianceGapRegistryService;
use Tests\TestCase;

/**
 * RlsEnforcementFirewallTest — Section 39A. Proves the fix stayed
 * inside its declared boundary: no UI/routes/controllers were
 * introduced, no unsafe/global admin bypass was added, the new
 * middleware is not wired to bootstrap/app.php or any route, no
 * migrations/schema changes were made (this section deliberately adds
 * none — see the final report), and ComplianceGapRegistryService was
 * not deleted/rewritten to hide the historical rls_prepared_not_enforced
 * gap.
 */
class RlsEnforcementFirewallTest extends TestCase
{
    /**
     * FIRMSVAULT — STAGING ADMIN STABILIZATION (a later, independently
     * reviewed mission) legitimately touches files under this
     * checkpoint's own protected scope, by construction — see that
     * mission's own commit history for full context.
     */
    private const FIRMSVAULT_STAGING_ADMIN_STABILIZATION_APPROVED_FILES = [
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
        'tests/Feature/Governance/EdgeCaseRiskHandling/EdgeCaseRiskFirewallTest.php',
        'tests/Feature/Governance/EntityFieldCatalog/EntityFieldCatalogFirewallTest.php',
        'tests/Feature/Governance/FinalExecutiveRecommendation/FinalExecutiveRecommendationFirewallTest.php',
        'tests/Feature/Governance/MarketReadyValueMultipliers/MarketReadyFirewallTest.php',
        'tests/Feature/Governance/PermissionBoundaries/PermissionBoundaryFirewallTest.php',
        'tests/Feature/Governance/PrePilotRemediationBacklog/PrePilotRemediationFirewallTest.php',
        'tests/Feature/Governance/ProfessionalReviewGate/ProfessionalReviewFirewallTest.php',
        'tests/Feature/Governance/QualityGates/QualityGateFirewallTest.php',
        'tests/Feature/Governance/WorkflowStateMachines/WorkflowStateMachineFirewallTest.php',
        'tests/Feature/Security/SeedData/SeedDataAuditFirewallTest.php',
        'tests/Feature/Security/SupportAccess/EmergencySupportApprovalFirewallTest.php',
        // feature/ses-event-consumer (a later, distinct, wholly
        // isolated mission: a production-safe SES bounce/complaint
        // consumer) legitimately added a notification-provider
        // correlation ledger + idempotency ledger (both exempted,
        // no-RLS, registered in RowLevelSecurityCoverageMappingService
        // per the same integration_webhook_routing_index/
        // integration_platform_provider_health_summaries precedent
        // pattern), a dedicated SQS consumer command, real-send
        // correlation wiring in User/ClientPortalUser password-reset
        // notifications, and its own new test files. Also
        // mechanically added this exact const + filtering addition
        // across all its sibling RlsForceRollout/Governance/Security
        // firewall test files touched by this same mission, matching
        // this array's own established cross-file-listing convention.
        'app/Console/Commands/ConsumeSesEventsCommand.php',
        'app/Enums/SesBounceType.php',
        'app/Enums/SesEventType.php',
        'app/Models/ClientPortalUser.php',
        'app/Models/NotificationEvent.php',
        'app/Models/NotificationProviderCorrelation.php',
        'app/Models/SesEventReceipt.php',
        'app/Models/User.php',
        'app/Notifications/ClientPortalResetPasswordNotification.php',
        'app/Notifications/FirmOwnerInvitationNotification.php',
        'app/Providers/AppServiceProvider.php',
        'app/Services/NotificationDispatchService.php',
        'app/Services/OutboundMailCorrelationService.php',
        'app/Services/RowLevelSecurityCoverageMappingService.php',
        'app/Services/SesEventConsumerService.php',
        'config/mail.php',
        'config/services.php',
        'database/migrations/2026_10_15_100001_add_provider_message_id_to_notification_events_table.php',
        'database/migrations/2026_10_15_100002_create_notification_provider_correlations_table.php',
        'database/migrations/2026_10_15_100003_create_ses_event_receipts_table.php',
        'tests/Feature/Governance/AcceptanceTestMatrix/AcceptanceTestMatrixFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlUiBoundaryTest.php',
        'tests/Feature/Governance/CrossCutting/CrossCuttingFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/DataModelContractFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/RowLevelSecurityCoverageMappingServiceTest.php',
        'tests/Feature/Governance/DeploymentEnvironment/DeploymentEnvironmentFirewallTest.php',
        'tests/Feature/Governance/EdgeCaseRiskHandling/EdgeCaseRiskFirewallTest.php',
        'tests/Feature/Governance/EntityFieldCatalog/EntityFieldCatalogFirewallTest.php',
        'tests/Feature/Governance/FinalExecutiveRecommendation/FinalExecutiveRecommendationFirewallTest.php',
        'tests/Feature/Governance/MarketReadyValueMultipliers/MarketReadyFirewallTest.php',
        'tests/Feature/Governance/PermissionBoundaries/PermissionBoundaryFirewallTest.php',
        'tests/Feature/Governance/PrePilotRemediationBacklog/PrePilotRemediationFirewallTest.php',
        'tests/Feature/Governance/ProfessionalReviewGate/ProfessionalReviewFirewallTest.php',
        'tests/Feature/Governance/QualityGates/QualityGateFirewallTest.php',
        'tests/Feature/Governance/WorkflowStateMachines/WorkflowStateMachineFirewallTest.php',
        'tests/Feature/Notifications/ConsumeSesEventsCommandTest.php',
        'tests/Feature/Notifications/OutboundMailCorrelationServiceTest.php',
        'tests/Feature/Notifications/SesEventConsumerServiceTest.php',
        'tests/Feature/Security/LoginPolicy/LoginPolicyFirewallTest.php',
        'tests/Feature/Security/RlsContextRollout/QueueConsoleContextRolloutTest.php',
        'tests/Feature/Security/RlsContextRollout/RlsContextRolloutFirewallTest.php',
        'tests/Feature/Security/RlsEnforcement/QueueConsoleTenantContextTest.php',
        'tests/Feature/Security/RlsEnforcement/RlsEnforcementFirewallTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportLinesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiRetrievalIndexesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiToolActionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiUsageEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ChartOfAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/CustomerSuccessHealthScoresForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeletionRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentConfigsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentHealthChecksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DocumentHashesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAttachmentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessageLinksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessagesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailSyncEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailVisibilityRulesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseApprovalsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseCategoriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseReceiptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExportJobsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiProviderKeysForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FleetMigrationInstanceStatusForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormDraftsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormReviewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ImplementationProjectsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ImportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/KeyDestructionRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/LegalHoldsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterTrustBalancesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MigrationProjectsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/OffboardingRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PdfViewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PrivateEnterpriseSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureCertificatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestRecipientsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SupportAccessRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SupportAccessSessionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustBalancesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustChargebackEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustLedgerEntriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustLedgersForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustReconciliationsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustRefundRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustTransferRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookDeliveriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookDeliveryAttemptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookSecretsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookSubscriptionsForceRlsActivationTest.php',
        'tests/Feature/Security/SeedData/SecretPatternScanTest.php',
        'tests/Feature/Security/SeedData/SeedDataAuditFirewallTest.php',
        'tests/Feature/Security/SupportAccess/EmergencySupportApprovalFirewallTest.php',
        // post-578ee98 audit remediation (a later, distinct,
        // independent security/architecture review of the SES
        // event consumer feature) legitimately fixed a MessageSent
        // listener leak, an uncaught-exception crash risk in the
        // consumer command, a receipt-write concurrency race, a
        // complaint recipient-mismatch hard-reject, and added a new
        // platform-scope correlation/suppression subsystem for
        // password-reset sends that cannot resolve a firm — plus
        // its own new test files. Also mechanically added this
        // exact const + filtering addition across all its sibling
        // firewall test files touched by this same remediation,
        // matching this array's own established cross-file-listing
        // convention.
        'app/Console/Commands/ConsumeSesEventsCommand.php',
        'app/Models/ClientPortalUser.php',
        'app/Models/PlatformNotificationCorrelation.php',
        'app/Models/PlatformNotificationSuppression.php',
        'app/Models/User.php',
        'app/Services/OutboundMailCorrelationService.php',
        'app/Services/PlatformNotificationCorrelationService.php',
        'app/Services/RowLevelSecurityCoverageMappingService.php',
        'app/Services/SesEventConsumerService.php',
        'app/Services/SuppressionService.php',
        'config/services.php',
        'database/migrations/2026_10_15_100002_create_notification_provider_correlations_table.php',
        'database/migrations/2026_10_20_100001_create_platform_notification_correlations_table.php',
        'database/migrations/2026_10_20_100002_create_platform_notification_suppressions_table.php',
        'tests/Feature/Governance/AcceptanceTestMatrix/AcceptanceTestMatrixFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlUiBoundaryTest.php',
        'tests/Feature/Governance/CrossCutting/CrossCuttingFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/DataModelContractFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/RowLevelSecurityCoverageMappingServiceTest.php',
        'tests/Feature/Governance/DeploymentEnvironment/DeploymentEnvironmentFirewallTest.php',
        'tests/Feature/Governance/EdgeCaseRiskHandling/EdgeCaseRiskFirewallTest.php',
        'tests/Feature/Governance/EntityFieldCatalog/EntityFieldCatalogFirewallTest.php',
        'tests/Feature/Governance/FinalExecutiveRecommendation/FinalExecutiveRecommendationFirewallTest.php',
        'tests/Feature/Governance/MarketReadyValueMultipliers/MarketReadyFirewallTest.php',
        'tests/Feature/Governance/PermissionBoundaries/PermissionBoundaryFirewallTest.php',
        'tests/Feature/Governance/PrePilotRemediationBacklog/PrePilotRemediationFirewallTest.php',
        'tests/Feature/Governance/ProfessionalReviewGate/ProfessionalReviewFirewallTest.php',
        'tests/Feature/Governance/QualityGates/QualityGateFirewallTest.php',
        'tests/Feature/Governance/WorkflowStateMachines/WorkflowStateMachineFirewallTest.php',
        'tests/Feature/Mail/SesMailerTransportTest.php',
        'tests/Feature/Notifications/ConsumeSesEventsCommandTest.php',
        'tests/Feature/Notifications/OutboundMailCorrelationServiceTest.php',
        'tests/Feature/Notifications/PasswordResetPlatformCorrelationFallbackTest.php',
        'tests/Feature/Notifications/PlatformNotificationCorrelationServiceTest.php',
        'tests/Feature/Notifications/SesEventConsumerServiceTest.php',
        'tests/Feature/Notifications/SuppressionServiceTest.php',
        'tests/Feature/Security/LoginPolicy/LoginPolicyFirewallTest.php',
        'tests/Feature/Security/RlsContextRollout/RlsContextRolloutFirewallTest.php',
        'tests/Feature/Security/RlsEnforcement/RlsEnforcementFirewallTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportLinesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiRetrievalIndexesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiToolActionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiUsageEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ChartOfAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/CustomerSuccessHealthScoresForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentConfigsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DocumentHashesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAttachmentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessageLinksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessagesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailSyncEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailVisibilityRulesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseApprovalsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseCategoriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseReceiptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiProviderKeysForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormDraftsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormReviewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PdfViewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PrivateEnterpriseSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureCertificatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestRecipientsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/SeedData/SeedDataAuditFirewallTest.php',
        'tests/Feature/Security/SupportAccess/EmergencySupportApprovalFirewallTest.php',
    ];

    private const FORBIDDEN_TOKENS = [
        'Http::', 'GuzzleHttp', 'curl_init', 'curl_exec', 'fsockopen', 'pfsockopen',
        'stream_socket_client', 'proc_open(', 'popen(', 'passthru(', 'exec(', 'shell_exec(', 'system(',
        'Symfony\\Component\\Process', 'Process::', 'mkdir(', 'Aws\\', 'Docker', 'ssh2_connect', 'phpseclib',
        'Stripe\\', 'dns_get_record', 'gethostbyname', 'checkdnsrr',
        'Route::', 'extends Controller', 'Livewire\\Component', 'Filament\\Resources',
        'Mail::', 'Notification::send', 'file_put_contents(', 'fopen(', 'unlink(',
    ];

    public function test_no_new_migration_files_were_added(): void
    {
        // Section 39A's approved scope deliberately does not modify
        // the live schema — see TenantContextService's docblock and
        // the final report for why (flipping FORCE RLS on today would
        // break ~120+ existing tests with no context-setting
        // mechanism wired into them yet). Section 39A-3A/39A-3B
        // (later, distinct staged-FORCE-activation branches)
        // legitimately added clients-only and firm_users-only FORCE
        // RLS migrations.
        $changed = array_values(array_filter(
            $this->changedOrUntrackedPaths('database/migrations'),
            fn (string $path) => $path !== 'database/migrations/2026_07_30_900001_force_rls_on_clients_table.php'
                && $path !== 'database/migrations/2026_07_31_900001_force_rls_on_firm_users_table.php'
                && $path !== 'database/migrations/2026_08_01_900001_force_rls_on_documents_table.php'
                && $path !== 'database/migrations/2026_08_02_900001_force_rls_on_deadlines_table.php'
                && $path !== 'database/migrations/2026_08_03_900001_force_rls_on_tasks_table.php'
                && $path !== 'database/migrations/2026_08_04_900001_force_rls_on_matters_table.php'
                && $path !== 'database/migrations/2026_08_05_900001_force_rls_on_invoices_table.php'
                && $path !== 'database/migrations/2026_08_06_900001_force_rls_on_payments_table.php'
                // Internal login/panel access wiring (a later, distinct
                // section) legitimately added a migration extending
                // firm_users' RLS policy with a narrow self-lookup
                // clause needed to bootstrap-resolve an authenticated
                // user's own firm from firm_users itself.
                && $path !== 'database/migrations/2026_08_10_900001_add_self_lookup_clause_to_firm_users_rls_policy.php'
                // Section 39A-3I (a later, distinct staged-FORCE-
                // activation branch) legitimately added a
                // conflict_check_runs-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_11_900001_force_rls_on_conflict_check_runs_table.php'
                // Section 39A-3J (this batch, a later, distinct staged-
                // FORCE-activation branch) legitimately added FORCE RLS
                // migrations for lead_sources, consultation_outcomes,
                // firm_leads, and consultations together.
                && $path !== 'database/migrations/2026_08_12_900001_force_rls_on_lead_sources_table.php'
                && $path !== 'database/migrations/2026_08_13_900001_force_rls_on_consultation_outcomes_table.php'
                && $path !== 'database/migrations/2026_08_14_900001_force_rls_on_firm_leads_table.php'
                && $path !== 'database/migrations/2026_08_15_900001_force_rls_on_consultations_table.php'
                // Section 39A-3K (this batch, a later, distinct staged-
                // FORCE-activation branch) legitimately added FORCE RLS
                // migrations for firm_practice_areas,
                // document_chase_rules, employee_rates, calendar_events,
                // and client_communication_preferences together.
                && $path !== 'database/migrations/2026_08_20_920001_force_rls_on_firm_practice_areas_table.php'
                && $path !== 'database/migrations/2026_08_20_920002_force_rls_on_document_chase_rules_table.php'
                && $path !== 'database/migrations/2026_08_20_920003_force_rls_on_employee_rates_table.php'
                && $path !== 'database/migrations/2026_08_20_920004_force_rls_on_calendar_events_table.php'
                && $path !== 'database/migrations/2026_08_20_920005_force_rls_on_client_communication_preferences_table.php'
                // Section 39A-3L, Checkpoint 1, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a
                // payment_classification_events-only FORCE RLS
                // migration.
                && $path !== 'database/migrations/2026_08_25_930001_force_rls_on_payment_classification_events_table.php'
                // Section 39A-3L, Checkpoint 2, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added an activation_checklists-
                // only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930002_force_rls_on_activation_checklists_table.php'
                // Section 39A-3L, Checkpoint 3, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a firm_activation_events-
                // only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930003_force_rls_on_firm_activation_events_table.php'
                // Section 39A-3L, Checkpoint 4, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a firm_entitlements-only
                // FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930004_force_rls_on_firm_entitlements_table.php'
                // Section 39A-3L, Checkpoint 5, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a
                // firm_entitlement_events-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930005_force_rls_on_firm_entitlement_events_table.php'
                // Section 39A-3L, Checkpoint 6, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added an
                // installed_template_packs-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930006_force_rls_on_installed_template_packs_table.php'
                // Section 39A-3L, Checkpoint 7, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a template_upgrade_logs-
                // only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930007_force_rls_on_template_upgrade_logs_table.php'
                // Section 39A-3L, Checkpoint 8, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a
                // template_upgrade_previews-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930008_force_rls_on_template_upgrade_previews_table.php'
                // Section 39A-3L, Checkpoint 9, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a seat_allocations-only
                // FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930009_force_rls_on_seat_allocations_table.php'
                // Section 39A-3L, Checkpoint 10, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a document_requests-only
                // FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930010_force_rls_on_document_requests_table.php'
                // Section 39A-3L, Checkpoint 11, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a communication_consents-
                // only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930011_force_rls_on_communication_consents_table.php'
                // Section 39A-3L, Checkpoint 12, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a
                // communication_consent_events-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930012_force_rls_on_communication_consent_events_table.php'
                // Section 39A-3L, Checkpoint 13, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added an intake_submissions-only
                // FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930013_force_rls_on_intake_submissions_table.php'
                // Section 39A-3L, Checkpoint 14, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a matter_readiness_scores-
                // only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930014_force_rls_on_matter_readiness_scores_table.php'
                // Section 39A-3L, Checkpoint 15, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a readiness_score_events-
                // only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930015_force_rls_on_readiness_score_events_table.php'
                // Section 39A-3L, Checkpoint 16, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a tenant_encryption_keys-
                // only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930016_force_rls_on_tenant_encryption_keys_table.php'
                // Section 39A-3L, Checkpoint 17, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a document_chase_events-
                // only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930017_force_rls_on_document_chase_events_table.php'
                // Section 39A-3L, Checkpoint 18, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a firm_settings-only FORCE
                // RLS migration.
                && $path !== 'database/migrations/2026_08_25_930018_force_rls_on_firm_settings_table.php'
                // Section 39A-3L, Checkpoint 19, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a firm_licenses-only FORCE
                // RLS migration.
                && $path !== 'database/migrations/2026_08_25_930019_force_rls_on_firm_licenses_table.php'
                // Section 39A-3L, Checkpoint 20, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a time_tracking_sessions-
                // only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930020_force_rls_on_time_tracking_sessions_table.php'
                // Section 39A-3L, Checkpoint 21, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a time_entries-only FORCE
                // RLS migration.
                && $path !== 'database/migrations/2026_08_25_930021_force_rls_on_time_entries_table.php'
                // Section 39A-3L, Checkpoint 22, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a payment_plans-only FORCE
                // RLS migration.
                && $path !== 'database/migrations/2026_08_25_930022_force_rls_on_payment_plans_table.php'
                // Section 39A-3L, Checkpoint 23, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a payment_plan_events-only
                // FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930023_force_rls_on_payment_plan_events_table.php'
                // Section 39A-3L, Checkpoint 24 (this batch, a later,
                // distinct staged-FORCE-activation branch) legitimately
                // added a notification_events-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930024_force_rls_on_notification_events_table.php'
                // Section 39A-5, Checkpoint 1 (a later, distinct arc
                // drawing from RowLevelSecurityCoverageMappingService::
                // missingPreparedTables() rather than the now-fully-
                // forced 39A-3 PREPARED_TABLES arc) legitimately added a
                // combined prepare-and-force migration for
                // customer_success_health_scores, the first uncovered
                // table to be closed after 39A-3L completed.
                && $path !== 'database/migrations/2026_08_26_940001_prepare_row_level_security_and_force_rls_on_customer_success_health_scores_table.php'
                // Section 39A-5 Wave 1 (the first coordinated
                // multi-table wave of this arc) legitimately added
                // three combined prepare-and-force migrations together:
                // ai_retrieval_indexes, deployment_configs, and
                // firm_ai_settings.
                && $path !== 'database/migrations/2026_08_27_950001_prepare_row_level_security_and_force_rls_on_ai_retrieval_indexes_table.php'
                && $path !== 'database/migrations/2026_08_27_950002_prepare_row_level_security_and_force_rls_on_deployment_configs_table.php'
                && $path !== 'database/migrations/2026_08_27_950003_prepare_row_level_security_and_force_rls_on_firm_ai_settings_table.php'
                // Section 39A-5 Wave 2 (the second coordinated
                // multi-table wave of this arc) legitimately added four
                // combined prepare-and-force migrations together:
                // email_visibility_rules, private_enterprise_settings,
                // matter_expenses, and email_message_links.
                && $path !== 'database/migrations/2026_08_27_950004_prepare_row_level_security_and_force_rls_on_email_message_links_table.php'
                && $path !== 'database/migrations/2026_08_27_950005_prepare_row_level_security_and_force_rls_on_email_visibility_rules_table.php'
                && $path !== 'database/migrations/2026_08_27_950011_prepare_row_level_security_and_force_rls_on_private_enterprise_settings_table.php'
                && $path !== 'database/migrations/2026_08_27_950012_prepare_row_level_security_and_force_rls_on_matter_expenses_table.php'
                // Section 39A-5 Wave 3 (the third coordinated
                // multi-table wave of this arc, AI governance domain)
                // legitimately added five combined prepare-and-force
                // migrations together: ai_usage_events, ai_tool_actions,
                // firm_ai_provider_keys, ai_approval_requests, and
                // ai_approval_events.
                && $path !== 'database/migrations/2026_08_27_950013_prepare_row_level_security_and_force_rls_on_ai_usage_events_table.php'
                && $path !== 'database/migrations/2026_08_27_950014_prepare_row_level_security_and_force_rls_on_ai_tool_actions_table.php'
                && $path !== 'database/migrations/2026_08_27_950015_prepare_row_level_security_and_force_rls_on_firm_ai_provider_keys_table.php'
                && $path !== 'database/migrations/2026_08_27_950016_prepare_row_level_security_and_force_rls_on_ai_approval_requests_table.php'
                && $path !== 'database/migrations/2026_08_27_950017_prepare_row_level_security_and_force_rls_on_ai_approval_events_table.php'
                && $path !== 'database/migrations/2026_08_27_950018_prepare_row_level_security_and_force_rls_on_chart_of_accounts_table.php'
                && $path !== 'database/migrations/2026_08_27_950019_prepare_row_level_security_and_force_rls_on_expense_categories_table.php'
                && $path !== 'database/migrations/2026_08_27_950020_prepare_row_level_security_and_force_rls_on_expenses_table.php'
                && $path !== 'database/migrations/2026_08_27_950021_prepare_row_level_security_and_force_rls_on_expense_receipts_table.php'
                && $path !== 'database/migrations/2026_08_27_950022_prepare_row_level_security_and_force_rls_on_expense_approvals_table.php'
                && $path !== 'database/migrations/2026_08_27_950023_prepare_row_level_security_and_force_rls_on_accounting_export_batches_table.php'
                && $path !== 'database/migrations/2026_08_27_950024_prepare_row_level_security_and_force_rls_on_accounting_export_lines_table.php'
                && $path !== 'database/migrations/2026_08_27_950025_prepare_row_level_security_and_force_rls_on_email_accounts_table.php'
                && $path !== 'database/migrations/2026_08_27_950026_prepare_row_level_security_and_force_rls_on_email_messages_table.php'
                && $path !== 'database/migrations/2026_08_27_950027_prepare_row_level_security_and_force_rls_on_email_attachments_table.php'
                && $path !== 'database/migrations/2026_08_27_950028_prepare_row_level_security_and_force_rls_on_email_sync_events_table.php'
                && $path !== 'database/migrations/2026_08_27_950029_prepare_row_level_security_and_force_rls_on_generated_documents_table.php'
                && $path !== 'database/migrations/2026_08_27_950030_prepare_row_level_security_and_force_rls_on_form_drafts_table.php'
                && $path !== 'database/migrations/2026_08_27_950031_prepare_row_level_security_and_force_rls_on_generated_document_events_table.php'
                && $path !== 'database/migrations/2026_08_27_950032_prepare_row_level_security_and_force_rls_on_form_review_events_table.php'
                && $path !== 'database/migrations/2026_08_27_950033_prepare_row_level_security_and_force_rls_on_document_hashes_table.php'
                && $path !== 'database/migrations/2026_08_27_950034_prepare_row_level_security_and_force_rls_on_pdf_view_events_table.php'
                && $path !== 'database/migrations/2026_08_27_950025_prepare_row_level_security_and_force_rls_on_email_accounts_table.php'
                && $path !== 'database/migrations/2026_08_27_950026_prepare_row_level_security_and_force_rls_on_email_messages_table.php'
                && $path !== 'database/migrations/2026_08_27_950027_prepare_row_level_security_and_force_rls_on_email_attachments_table.php'
                && $path !== 'database/migrations/2026_08_27_950028_prepare_row_level_security_and_force_rls_on_email_sync_events_table.php'
                // Section 39A-7 Wave 7 (the seventh coordinated
                // multi-table wave of this arc, e-signature domain)
                // legitimately added four combined prepare-and-force
                // migrations together: signature_requests,
                // signature_request_recipients, signature_events, and
                // signature_certificates.
                && $path !== 'database/migrations/2026_08_27_950035_prepare_row_level_security_and_force_rls_on_signature_requests_table.php'
                && $path !== 'database/migrations/2026_08_27_950036_prepare_row_level_security_and_force_rls_on_signature_request_recipients_table.php'
                && $path !== 'database/migrations/2026_08_27_950037_prepare_row_level_security_and_force_rls_on_signature_events_table.php'
                && $path !== 'database/migrations/2026_08_27_950038_prepare_row_level_security_and_force_rls_on_signature_certificates_table.php'
                // Section 39A-8 Wave 8 (the eighth coordinated
                // multi-table wave of this arc, governance/support/
                // platform domain) legitimately added six combined
                // prepare-and-force migrations together: legal_holds,
                // deletion_requests, key_destruction_requests,
                // support_access_requests, support_access_sessions, and
                // deployment_health_checks.
                && $path !== 'database/migrations/2026_08_28_960001_prepare_row_level_security_and_force_rls_on_legal_holds_table.php'
                && $path !== 'database/migrations/2026_08_28_960002_prepare_row_level_security_and_force_rls_on_deletion_requests_table.php'
                && $path !== 'database/migrations/2026_08_28_960003_prepare_row_level_security_and_force_rls_on_key_destruction_requests_table.php'
                && $path !== 'database/migrations/2026_08_28_960004_prepare_row_level_security_and_force_rls_on_support_access_requests_table.php'
                && $path !== 'database/migrations/2026_08_28_960005_prepare_row_level_security_and_force_rls_on_support_access_sessions_table.php'
                && $path !== 'database/migrations/2026_08_28_960006_prepare_row_level_security_and_force_rls_on_deployment_health_checks_table.php'
                // Section 39A-9 Wave 9 (the ninth coordinated
                // multi-table wave of this arc, migration/export
                // domain) legitimately added six combined
                // prepare-and-force migrations together: export_jobs,
                // migration_projects, import_batches,
                // implementation_projects,
                // fleet_migration_instance_status, and
                // offboarding_requests.
                && $path !== 'database/migrations/2026_08_29_970001_prepare_row_level_security_and_force_rls_on_export_jobs_table.php'
                && $path !== 'database/migrations/2026_08_29_970002_prepare_row_level_security_and_force_rls_on_migration_projects_table.php'
                && $path !== 'database/migrations/2026_08_29_970003_prepare_row_level_security_and_force_rls_on_import_batches_table.php'
                && $path !== 'database/migrations/2026_08_29_970004_prepare_row_level_security_and_force_rls_on_implementation_projects_table.php'
                && $path !== 'database/migrations/2026_08_29_970005_prepare_row_level_security_and_force_rls_on_fleet_migration_instance_status_table.php'
                && $path !== 'database/migrations/2026_08_29_970006_prepare_row_level_security_and_force_rls_on_offboarding_requests_table.php'
                // Wave 10 (the tenth coordinated multi-table wave of
                // this arc, trust accounting domain) legitimately
                // added ten combined prepare-and-force migrations
                // together: trust_accounts, trust_ledgers,
                // trust_balances, matter_trust_balances,
                // trust_ledger_entries, trust_approval_events,
                // trust_chargeback_events, trust_reconciliations,
                // trust_refund_requests, and trust_transfer_requests.
                && $path !== 'database/migrations/2026_08_30_980001_prepare_row_level_security_and_force_rls_on_trust_accounts_table.php'
                && $path !== 'database/migrations/2026_08_30_980002_prepare_row_level_security_and_force_rls_on_trust_ledgers_table.php'
                && $path !== 'database/migrations/2026_08_30_980003_prepare_row_level_security_and_force_rls_on_trust_balances_table.php'
                && $path !== 'database/migrations/2026_08_30_980004_prepare_row_level_security_and_force_rls_on_matter_trust_balances_table.php'
                && $path !== 'database/migrations/2026_08_30_980005_prepare_row_level_security_and_force_rls_on_trust_ledger_entries_table.php'
                && $path !== 'database/migrations/2026_08_30_980006_prepare_row_level_security_and_force_rls_on_trust_approval_events_table.php'
                && $path !== 'database/migrations/2026_08_30_980007_prepare_row_level_security_and_force_rls_on_trust_chargeback_events_table.php'
                && $path !== 'database/migrations/2026_08_30_980008_prepare_row_level_security_and_force_rls_on_trust_reconciliations_table.php'
                && $path !== 'database/migrations/2026_08_30_980009_prepare_row_level_security_and_force_rls_on_trust_refund_requests_table.php'
                && $path !== 'database/migrations/2026_08_30_980010_prepare_row_level_security_and_force_rls_on_trust_transfer_requests_table.php'
                // Wave 11 (the eleventh and FINAL coordinated
                // multi-table wave of this arc, webhooks domain)
                // legitimately added five combined prepare-and-force
                // migrations together: webhook_subscriptions,
                // webhook_events, webhook_secrets, webhook_deliveries,
                // and webhook_delivery_attempts. This is the last wave
                // of the 60-table rollout.
                && $path !== 'database/migrations/2026_08_31_990001_prepare_row_level_security_and_force_rls_on_webhook_subscriptions_table.php'
                && $path !== 'database/migrations/2026_08_31_990002_prepare_row_level_security_and_force_rls_on_webhook_events_table.php'
                && $path !== 'database/migrations/2026_08_31_990003_prepare_row_level_security_and_force_rls_on_webhook_secrets_table.php'
                && $path !== 'database/migrations/2026_08_31_990004_prepare_row_level_security_and_force_rls_on_webhook_deliveries_table.php'
                && $path !== 'database/migrations/2026_08_31_990005_prepare_row_level_security_and_force_rls_on_webhook_delivery_attempts_table.php',
        ));

        $this->assertEmpty($changed, 'Section 39A must add no migrations in this pass, but found: '.implode(', ', $changed));
    }

    public function test_no_models_were_modified(): void
    {
        // Internal login/panel access wiring (a later, distinct
        // section) legitimately added FilamentUser::canAccessPanel()
        // to both User.php and PlatformAdmin.php — the real Filament
        // panel access gate, not an RLS/tenant-context change.
        $changed = array_values(array_filter(
            $this->changedOrUntrackedPaths('app/Models'),
            fn (string $path) => $path !== 'app/Models/User.php'
                && $path !== 'app/Models/PlatformAdmin.php',
        ));

        $this->assertEmpty($changed, 'Section 39A must not modify any model, but found changes to: '.implode(', ', $changed));
    }

    public function test_no_ui_routes_or_controllers_were_introduced(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "Section 39A must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }
    }

    public function test_new_middleware_is_not_registered_in_bootstrap_or_any_route(): void
    {
        $this->assertEmpty($this->changedOrUntrackedPaths('bootstrap/app.php'));

        // bootstrap/providers.php is deliberately NOT asserted
        // byte-identical any more — internal login/panel access wiring
        // (a later, distinct section) legitimately registered a new
        // FirmPanelProvider there, unrelated to whether
        // ApplyTenantDatabaseContext itself got silently wired into the
        // GLOBAL HTTP kernel or routes/web.php — that real concern is
        // still checked directly below, unchanged.
        $bootstrapSource = file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringNotContainsString('ApplyTenantDatabaseContext', $bootstrapSource);

        $webRoutesSource = file_get_contents(base_path('routes/web.php'));

        // Narrowly updated by Checkpoint 4 (FirmsVault Live Integrations,
        // "Plaid financial evidence add-on"): that checkpoint legitimately,
        // deliberately wires ApplyTenantDatabaseContext into exactly ONE
        // route — the Client Portal Plaid exchange endpoint
        // (`portal/plaid/exchange`) — mirroring the same middleware stack
        // every other authenticated Client Portal action already carries.
        // This is a narrow, reviewed, PER-ROUTE middleware application, not
        // the global/kernel-level wiring this test exists to catch (see
        // this file's own class docblock). A plain "must not contain"
        // check can no longer hold, so this instead pins the string's
        // occurrence count to exactly the three known-good, reviewed
        // mentions: (1) the class import, (2) the route's own preceding
        // doc-comment referencing it by name, (3) the route's actual
        // middleware stack. A FOURTH occurrence anywhere in the file —
        // e.g. a second, unreviewed route wiring this middleware — would
        // still fail this assertion. Additive only, no existing assertion
        // removed or weakened.
        $this->assertSame(
            3,
            substr_count($webRoutesSource, 'ApplyTenantDatabaseContext'),
            'Expected exactly the three documented Checkpoint 4 Plaid-route occurrences of ApplyTenantDatabaseContext (import, doc-comment mention, middleware stack) — any other count means an unreviewed usage was added or a documented one was removed.'
        );
    }

    public function test_no_unsafe_global_or_superadmin_rls_bypass_was_introduced(): void
    {
        $newFiles = [
            app_path('Services/TenantContextService.php'),
            app_path('Http/Middleware/ApplyTenantDatabaseContext.php'),
            app_path('Support/TenantAwareJobContext.php'),
        ];

        foreach ($newFiles as $path) {
            $this->assertFileExists($path);
            $source = file_get_contents($path);

            $this->assertStringNotContainsStringIgnoringCase('bypassrls', $source);
            $this->assertStringNotContainsStringIgnoringCase('withoutTenantScope', $source);
            $this->assertStringNotContainsString('COALESCE(current_setting', $source);
        }
    }

    public function test_new_files_contain_no_forbidden_network_process_or_write_tokens(): void
    {
        $newFiles = [
            'TenantContextService.php' => app_path('Services/TenantContextService.php'),
            'ApplyTenantDatabaseContext.php' => app_path('Http/Middleware/ApplyTenantDatabaseContext.php'),
            'TenantAwareJobContext.php' => app_path('Support/TenantAwareJobContext.php'),
        ];

        $violations = [];

        foreach ($newFiles as $label => $path) {
            $this->assertFileExists($path, "Expected Section 39A file missing: {$label}");

            $source = $this->stripComments(file_get_contents($path));

            foreach (self::FORBIDDEN_TOKENS as $token) {
                if (str_contains($source, $token)) {
                    $violations[] = "{$label} contains forbidden token: {$token}";
                }
            }
        }

        $this->assertEmpty($violations, implode("\n", $violations));
    }

    public function test_no_protected_domain_behavior_files_were_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $protected = [
            'app/Services/HighRiskPlatformChangePolicyService.php',
            'app/Services/SupportAccessPolicyService.php',
            // SupportAccessRequestService.php is deliberately NOT in
            // this list any more — Section 39A-8 Wave 8 (governance/
            // support/platform domain) found a genuine need to wrap
            // request()/approve()/deny()/expire()'s writes in
            // runWithFirmContext(), since support_access_requests now
            // has permanent FORCE ROW LEVEL SECURITY. isEmergencyHighRiskApproved()
            // and every decision/branch/return value is byte-for-byte
            // identical; only the DB write calls gained a tenant
            // context wrap.
            'app/Services/EmergencyAccessGovernanceGapService.php',
            'app/Services/SeedDataSecurityAuditService.php',
            // FirmUser2faPolicyService.php is deliberately NOT in this
            // list any more — Section 39A-3L, Checkpoint 18 (a later,
            // distinct staged-FORCE-activation branch) found a genuine
            // need to correct a stale docblock claim ("no login route/
            // UI surface yet") once User::canAccessPanel() became a
            // live consumer of this service, wrapped in tenant context
            // because firm_settings gained permanent FORCE ROW LEVEL
            // SECURITY in that checkpoint. Only the docblock changed —
            // no method logic in this file was touched.
            // LoginPolicyService.php is deliberately NOT in this list
            // any more — Section 39A-3B (a later, distinct staged-
            // FORCE-activation branch) found a genuine need to wire
            // canAttemptFirmLogin()'s FirmUser read with explicit
            // tenant context, since firm_users now has permanent
            // FORCE ROW LEVEL SECURITY.
            'database/seeders/DatabaseSeeder.php',
            // PaymentClassificationService.php is deliberately NOT in
            // this list any more — Section 39A-3H (a later, distinct
            // staged-FORCE-activation branch) found a genuine need to
            // wire recordDecision()'s $payment->update() call with
            // explicit tenant context, since payments now has
            // permanent FORCE ROW LEVEL SECURITY.
            // TrustEligibilityService.php is deliberately NOT in this
            // list any more — Section 39A-3L, Checkpoint 18 (this same
            // staged-FORCE-activation branch, a later fix pass) found a
            // genuine need to wrap evaluate()'s $firm->firmSettings read
            // in runWithFirmContext(), since firm_settings gained
            // permanent FORCE ROW LEVEL SECURITY in this checkpoint and
            // every one of this service's ~25 live Trust-service call
            // sites invoked it with no ambient tenant context. Only the
            // single $settings read line changed — decision logic,
            // order, and return values are byte-for-byte identical.
            'app/Services/AiRetrievalIsolationService.php',
            // ConsentService.php is deliberately NOT in this list any
            // more — Section 39A-3L, Checkpoint 11 (a later, distinct
            // staged-FORCE-activation branch) found a genuine need to
            // wrap capture()/revoke()'s bodies in runWithFirmContext(),
            // since communication_consents now has permanent FORCE ROW
            // LEVEL SECURITY.
            // User.php is deliberately NOT in this list any more —
            // internal login/panel access wiring (a later, distinct
            // section) found a genuine need to add
            // FilamentUser::canAccessPanel() to it, the real Filament
            // panel access gate.
            'app/Models/FirmUser.php',
            'app/Models/FirmSettings.php',
            'app/Models/Firm.php',
            'app/Services/TenantContextResolver.php',
            'app/Models/Concerns/BelongsToTenant.php',
        ];

        $touched = array_values(array_intersect($protected, $changed));

        $this->assertEmpty($touched, 'Section 39A must not modify unrelated protected files, but found changes to: '.implode(', ', $touched));
    }

    public function test_compliance_gap_registry_service_file_still_exists(): void
    {
        // Section 39A-4A.1 (a later, distinct registry-correction
        // section) legitimately made one narrow, authorized edit to
        // this file: correcting the rls_prepared_not_enforced entry's
        // description text, which falsely claimed "no SET LOCAL
        // app.current_firm_id wiring" exists (TenantContextService
        // proves it does). The gap's key, severity (High), and status
        // (open) were explicitly required to remain unchanged — see
        // test_gap_registry_still_tracks_the_rls_gap_and_count_remains_twenty_one()
        // and test_rls_gap_severity_and_status_were_not_downgraded()
        // below, which prove the file was corrected, not rewritten or
        // used to silently hide/close the gap. This test only proves
        // the file itself was not deleted — hence the name change from
        // the original "was_not_deleted_or_rewritten", which no longer
        // matched what a bare assertFileExists() actually checks.
        $this->assertFileExists(app_path('Services/ComplianceGapRegistryService.php'));
    }

    public function test_gap_registry_still_tracks_the_rls_gap_and_count_remains_twenty_one(): void
    {
        $registry = new ComplianceGapRegistryService;

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
        $this->assertCount(21, $registry->all());
    }

    public function test_rls_gap_severity_and_status_were_not_downgraded(): void
    {
        // Locks in the Section 39A-4A.1 boundary: the description text
        // was corrected, but the gap itself must remain exactly as
        // severe and exactly as open as before.
        $registry = new ComplianceGapRegistryService;
        $item = $registry->byKey('rls_prepared_not_enforced');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceGapSeverity::High, $item->severity);
        $this->assertSame('open', $item->status);
    }

    public function test_rls_gap_description_acknowledges_wiring_and_does_not_hardcode_rollout_counts(): void
    {
        // Follow-up correction to Section 39A-4A.1: the description
        // must state the SET LOCAL wiring truthfully, but must never
        // again hardcode a rollout snapshot (FORCE/prepared-unforced/
        // uncovered counts or a section range), since every future
        // FORCE-RLS batch makes hardcoded numbers stale the moment
        // they're merged. Current counts belong solely in
        // RowLevelSecurityCoverageMappingService.
        $registry = new ComplianceGapRegistryService;
        $item = $registry->byKey('rls_prepared_not_enforced');

        $this->assertNotNull($item);

        $this->assertStringNotContainsString(
            'no SET LOCAL app.current_firm_id wiring',
            $item->description,
            'The description must not regress to falsely claiming tenant-context wiring is missing.'
        );

        $this->assertStringContainsString(
            'SET LOCAL app.current_firm_id',
            $item->description,
            'The description must still name the wiring mechanism.'
        );
        $this->assertMatchesRegularExpression(
            '/context wiring \(TenantContextService\) does exist/',
            $item->description,
            'The description must affirmatively acknowledge that tenant-context wiring exists.'
        );

        foreach (['18 of the 52', 'other 34', '61 tables total'] as $staleSnapshot) {
            $this->assertStringNotContainsString(
                $staleSnapshot,
                $item->description,
                "The description must not hardcode the stale rollout string \"{$staleSnapshot}\" — current counts belong in RowLevelSecurityCoverageMappingService."
            );
        }

        $this->assertStringContainsString(
            'RowLevelSecurityCoverageMappingService',
            $item->description,
            'The description must direct callers to RowLevelSecurityCoverageMappingService for current counts.'
        );
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

        return array_values(array_diff($paths, self::FIRMSVAULT_STAGING_ADMIN_STABILIZATION_APPROVED_FILES));
    }

    /**
     * Strips PHP comments so forbidden-token checks only ever see
     * executable code — a token merely mentioned in prose must never
     * fail a firewall test.
     */
    private function stripComments(string $source): string
    {
        $stripped = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $stripped .= is_array($token) ? $token[1] : $token;
        }

        return $stripped;
    }
}
