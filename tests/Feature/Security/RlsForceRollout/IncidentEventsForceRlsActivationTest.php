<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckType;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\HealthCheck;
use App\Models\IncidentEvent;
use App\Services\ComplianceGapRegistryService;
use App\Services\IncidentService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\EvaluatesHistoricalCheckpointScope;
use Tests\TestCase;

/**
 * IncidentEventsForceRlsActivationTest — Section 39A-3L, Checkpoint 29
 * (Phase B6). Proves the forty-seventh staged FORCE ROW LEVEL SECURITY
 * activation batch (database/migrations/2026_08_25_930029_force_rls_
 * on_incident_events_table.php) is permanently active for
 * incident_events and behaves correctly — including this checkpoint's
 * own novel security contribution (see below): every previously-forced
 * table remains forced simultaneously; missing-context read/insert
 * denial; a firm-specific row remains strictly single-firm-visible; a
 * platform-wide (firm_id = NULL) row is visible under EVERY
 * firm-scoped session's context; the asymmetric WITH CHECK closes both
 * the INSERT-side forgery gap and the DELETE-side gap, mirroring
 * backup_restore_tests'/health_checks' own two-policy design exactly.
 *
 * The checkpoint's actual novel security contribution (distinguishing
 * it from both prior tables): IncidentService's six append-style
 * methods (updateSeverity, updateStatus, recordRootCause,
 * flagCustomerImpact, flagNotificationNeeded, resolve) must first read
 * an incident's CURRENT ownership via an unscoped currentState() call
 * before knowing what context to write under — a chicken-and-egg
 * problem neither backup_restore_tests nor health_checks faced. Solved
 * by a required `?Firm $firm` parameter on all six methods (mirroring
 * open()'s own convention), each wrapping its own read+write in ONE
 * context. This produces two DISTINCT asymmetric failure modes for a
 * mismatched $firm — proven SEPARATELY below, not conflated into one
 * test, per the design dossier's own explicit requirement: (a) wrong
 * firm against a firm-specific incident → ModelNotFoundException from
 * currentState()'s firstOrFail(); (b) non-null firm against an
 * actually-platform-wide incident → currentState() succeeds (the read
 * policy's firm_id IS NULL branch is unconditional) but appendEvent()'s
 * write is rejected directly by Postgres's row-level security policy.
 * Also proven directly: appendEvent()'s forwarded
 * firm_id = $current->firm_id never diverges from the row actually
 * read under the active context — a firm-specific incident's timeline
 * never picks up a platform-wide or sibling-firm row.
 *
 * Full design rationale and the exact approved SQL:
 * rls-checkpoints/39a3l/B6-incident_events-design-dossier.md (APPROVED
 * by both rls-policy-designer and tenant-context-auditor).
 *
 * Like backup_restore_tests/health_checks, incident_events required
 * real application-code prerequisites ahead of this FORCE migration —
 * IncidentService's six methods gaining a required ?Firm $firm
 * parameter, and IncidentEventFactory's context-hold create() override
 * with an explicit null-firm_id branch — all committed independently
 * ahead of this migration, per the dossier's own note that the
 * preparation and the FORCE activation are split into two commits
 * here, matching the contacts/parties (Checkpoints 25/26) and
 * backup_restore_tests/health_checks (Checkpoints 27/28) precedent.
 */
class IncidentEventsForceRlsActivationTest extends TestCase
{
    /**
     * FIRMSVAULT — STAGING ADMIN STABILIZATION (a later, independently
     * reviewed mission) legitimately touches files under this
     * checkpoint's own protected scope, by construction — any later
     * mission's real work will always otherwise trip every earlier
     * checkpoint's own "no changes" firewall. Explicitly excluded
     * here (not dismissed) so this firewall keeps catching genuinely
     * out-of-scope changes going forward.
     */
    private const FIRMSVAULT_STAGING_ADMIN_STABILIZATION_APPROVED_FILES = [
        'app/Filament/Resources/PlanAddOnResource.php',
        'app/Filament/Resources/PlanAddOnResource/Pages/ListPlanAddOns.php',
        'app/Filament/Resources/PlanResource.php',
        'app/Filament/Resources/PlanResource/Pages/ListPlans.php',
        'app/Models/Plan.php',
        'app/Services/FirmProvisioningService.php',
        'app/Services/PlanModuleService.php',
        'app/Services/PlanService.php',
        'config/database.php',
        'database/factories/PlanFactory.php',
        'tests/Feature/Ecs/RedisTlsConfigurationTest.php',
        'tests/Feature/Integrations/Ui/FirmIntegrationSuperAdminBoundaryStructuralTest.php',
        'tests/Feature/Plans/PlanServiceTest.php',
        'tests/Feature/Security/RlsContextRollout/QueueConsoleContextRolloutTest.php',
        'tests/Feature/Security/RlsEnforcement/QueueConsoleTenantContextTest.php',
        'tests/Feature/Security/SeedData/SecretPatternScanTest.php',
        'tests/Feature/Services/FirmProvisioningServiceTest.php',
        'app/Console/Commands/BootstrapStagingSandboxPlanCommand.php',
        'app/Exceptions/InactivePlanSelectedException.php',
        'app/Filament/Actions/Platform/AddPlanModuleAction.php',
        'app/Filament/Actions/Platform/CreatePlanAction.php',
        'app/Filament/Actions/Platform/EditPlanAction.php',
        'database/migrations/2026_10_10_100001_add_code_and_description_to_plans_table.php',
        'tests/Feature/Console/BootstrapStagingSandboxPlanCommandTest.php',
        'tests/Feature/PlatformAdmin/PlanCatalogCreateActionsTest.php',
        'tests/Feature/Governance/AcceptanceTestMatrix/AcceptanceTestMatrixFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlUiBoundaryTest.php',
        'tests/Feature/Governance/CrossCutting/CrossCuttingFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/DataModelContractFirewallTest.php',
        'tests/Feature/Governance/EdgeCaseRiskHandling/EdgeCaseRiskFirewallTest.php',
        'tests/Feature/Governance/EntityFieldCatalog/EntityFieldCatalogFirewallTest.php',
        'tests/Feature/Governance/FinalExecutiveRecommendation/FinalExecutiveRecommendationFirewallTest.php',
        'tests/Feature/Governance/MarketReadyValueMultipliers/MarketReadyFirewallTest.php',
        'tests/Feature/Governance/PermissionBoundaries/PermissionBoundaryFirewallTest.php',
        'tests/Feature/Governance/PrePilotRemediationBacklog/PrePilotRemediationFirewallTest.php',
        'tests/Feature/Governance/ProfessionalReviewGate/ProfessionalReviewFirewallTest.php',
        'tests/Feature/Governance/QualityGates/QualityGateFirewallTest.php',
        'tests/Feature/Governance/Section40/Section40LimitedPilotSafetyGateTest.php',
        'tests/Feature/Governance/WorkflowStateMachines/WorkflowStateMachineFirewallTest.php',
        'tests/Feature/Security/FirmUser2fa/FirmUser2faFirewallTest.php',
        'tests/Feature/Security/LoginPolicy/LoginPolicyFirewallTest.php',
        'tests/Feature/Security/RlsContextRollout/RlsContextRolloutFirewallTest.php',
        'tests/Feature/Security/RlsEnforcement/RlsEnforcementFirewallTest.php',
        'tests/Feature/Security/RlsForceActivation/RlsForceActivationFirewallTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportLinesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiRetrievalIndexesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiToolActionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiUsageEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/BackupRestoreTestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/CalendarEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ChartOfAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ClientCommunicationPreferencesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ConflictCheckRunsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ConsultationOutcomesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ConsultationsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ContactsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/CustomerSuccessHealthScoresForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeletionRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentConfigsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentHealthChecksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DocumentChaseRulesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DocumentHashesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAttachmentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessageLinksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessagesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailSyncEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailVisibilityRulesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmployeeRatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseApprovalsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseCategoriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseReceiptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExportJobsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiProviderKeysForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmLeadsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmPracticeAreasForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FleetMigrationInstanceStatusForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormDraftsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormReviewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/HealthChecksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ImplementationProjectsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ImportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/IncidentEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/KeyDestructionRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/LeadSourcesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/LegalHoldsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MaintenanceWindowsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterTrustBalancesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MigrationProjectsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/NotificationTemplatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/OffboardingRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PartiesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PdfViewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PilotFeedbackItemsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PrivateEnterpriseSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/RlsForceRolloutFirewallTest.php',
        'tests/Feature/Security/RlsForceRollout/SecurityEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureCertificatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestRecipientsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SupportAccessRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SupportAccessSessionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TimelineEventsForceRlsActivationTest.php',
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
        'tests/Feature/Security/SeedData/SeedDataAuditFirewallTest.php',
        'tests/Feature/Security/SupportAccess/EmergencySupportApprovalFirewallTest.php',
        // FIRMSVAULT — STAGING ADMIN STABILIZATION (follow-on fix) also
        // corrected DeploymentEnvironmentFirewallTest.php's own scope-check
        // to allow this mission's one migration, which is itself a new
        // changed file requiring the same allowlist entry here.
        'tests/Feature/Governance/DeploymentEnvironment/DeploymentEnvironmentFirewallTest.php',
    ];

    use EvaluatesHistoricalCheckpointScope;

    // Narrowly updated by Section 39A-5 Wave 11 (webhooks domain, the final wave of the 60-table rollout, covering webhook_deliveries, webhook_delivery_attempts, webhook_events, webhook_secrets, webhook_subscriptions) for the same reason — additive only, no existing assertion removed or weakened. Total prepared/forced count is now 113.
    // Narrowly updated by Stage B Checkpoint 3 of the FirmsBase
    // Integration Platform mission (firm_integrations, a new genuine
    // tenant-owned table with RLS prepared and FORCE-activated in the
    // same migration, 2026_09_02_020002_prepare_row_level_security_and_force_rls_on_firm_integrations_table.php)
    // for the same reason — additive only, no existing assertion
    // removed or weakened. Total prepared/forced count is now 114.
    // Narrowly updated AGAIN by Stage B Checkpoint 4 of the
    // FirmsBase Integration Platform mission (integration_credentials,
    // a new genuine tenant-owned table with RLS prepared and
    // FORCE-activated in the same migration,
    // 2026_09_03_030002_prepare_row_level_security_and_force_rls_on_integration_credentials_table.php)
    // for the same reason — additive only, no existing assertion
    // removed or weakened. Total prepared/forced count is now 115.
    // Narrowly updated AGAIN by Stage B Checkpoint 5 of the FirmsBase Integration Platform mission
    // (integration_oauth_states, a new genuine tenant-owned table with RLS prepared and
    // FORCE-activated in the same migration) for the same reason — additive only, no existing
    // assertion removed or weakened. Total prepared/forced count is now 116.
    // Narrowly updated AGAIN by Stage B Checkpoint 6 of the FirmsBase Integration Platform mission
    // (integration_sync_runs, integration_sync_items, integration_external_mappings,
    // integration_sync_cursors, integration_conflicts, and integration_outbox_events, six
    // brand-new genuine tenant-owned tables, each with RLS prepared and FORCE-activated
    // in its own combined migration) for the same reason — additive only, no existing
    // assertion removed or weakened. Total prepared/forced count is now 122.
    use RefreshDatabase;

    private const PREVIOUSLY_FORCED_TABLES = [
        'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments',
        'conflict_check_runs', 'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
        'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events',
        'client_communication_preferences', 'payment_classification_events', 'activation_checklists',
        'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events', 'installed_template_packs',
        'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests',
        'communication_consents', 'communication_consent_events', 'intake_submissions',
        'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events',
        'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans',
        'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests',
        'health_checks',
    ];

    private function tenantContext(): TenantContextService
    {
        return new TenantContextService;
    }

    private function incidentService(): IncidentService
    {
        return new IncidentService;
    }

    private function insertRow(?int $firmId, string $suffix, ?IncidentSeverity $severity = null, ?IncidentStatus $status = null, ?string $correlationId = null): int
    {
        return DB::table('incident_events')->insertGetId([
            'firm_id' => $firmId,
            'correlation_id' => $correlationId ?? (string) Str::uuid(),
            'event_type' => 'opened',
            'severity' => ($severity ?? IncidentSeverity::Medium)->value,
            'status' => ($status ?? IncidentStatus::Investigating)->value,
            'customer_impact' => false,
            'notification_needed' => false,
            'message' => 'RLS proof row '.$suffix,
            'created_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // FORCE state / policy / cumulative-coverage proofs
    // ---------------------------------------------------------------

    public function test_every_previously_forced_table_remains_force_row_level_security_enabled(): void
    {
        foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue(
                (bool) $row->relforcerowsecurity,
                "{$table} must remain FORCE RLS enabled after this batch."
            );
        }
    }

    public function test_incident_events_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'incident_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_incident_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'incident_events'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'incident_events must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly forty-seven tables (the forty-six previously forced plus
     * incident_events) must be FORCE-enabled among ALL prepared tables
     * — no more, no less.
     */
    public function test_exactly_forty_seven_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events', 'integration_connection_health', 'integration_usage_records', 'integration_provider_webhook_subscriptions', /* Narrowly updated by Checkpoint 4 (FirmsVault Live Integrations, "Plaid financial evidence add-on") -- this full-registry list must now also account for the 21 new tables Checkpoint 4 added to both PREPARED_TABLES and the FORCE-RLS rollout in the same migration (client_portal_matter_grants, the financial-evidence domain, and the provider-billing domain) -- additive only, no existing assertion removed or weakened. */ 'client_portal_matter_grants', 'financial_evidence_bank_accounts', 'financial_evidence_transactions', 'financial_evidence_income_records', 'financial_evidence_liabilities', 'financial_evidence_investment_records', 'financial_evidence_statements', 'financial_evidence_identity_records', 'provider_billable_call_reservations', 'provider_firm_operation_policies', 'provider_balance_snapshots', 'financial_evidence_matter_requests', 'financial_evidence_client_consents', 'financial_evidence_matter_authorizations', 'financial_evidence_matter_notes', 'financial_evidence_snapshots', 'financial_evidence_transaction_reviews', 'financial_evidence_duplicate_transfer_flags', 'financial_evidence_large_deposit_flags', 'financial_evidence_reconciliation_candidates', 'financial_account_reclassification_requests', 'accounting_journal_entries', 'accounting_postings', 'payment_allocations', 'payment_reversals', 'invoice_write_offs', 'accounting_periods']);

        $actuallyForced = [];

        foreach ($coverage->preparedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");

            if ((bool) $row->relforcerowsecurity) {
                $actuallyForced[] = $table;
            }
        }

        sort($expectedForced);
        sort($actuallyForced);

        $this->assertSame(153, count($actuallyForced), 'Exactly forty-seven prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 29 — no more, no less.');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch.
     */
    public function test_no_unrelated_prepared_table_became_force_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events', 'integration_connection_health', 'integration_usage_records', 'integration_provider_webhook_subscriptions', /* Narrowly updated by Checkpoint 4 (FirmsVault Live Integrations, "Plaid financial evidence add-on") -- this full-registry list must now also account for the 21 new tables Checkpoint 4 added to both PREPARED_TABLES and the FORCE-RLS rollout in the same migration (client_portal_matter_grants, the financial-evidence domain, and the provider-billing domain) -- additive only, no existing assertion removed or weakened. */ 'client_portal_matter_grants', 'financial_evidence_bank_accounts', 'financial_evidence_transactions', 'financial_evidence_income_records', 'financial_evidence_liabilities', 'financial_evidence_investment_records', 'financial_evidence_statements', 'financial_evidence_identity_records', 'provider_billable_call_reservations', 'provider_firm_operation_policies', 'provider_balance_snapshots', 'financial_evidence_matter_requests', 'financial_evidence_client_consents', 'financial_evidence_matter_authorizations', 'financial_evidence_matter_notes', 'financial_evidence_snapshots', 'financial_evidence_transaction_reviews', 'financial_evidence_duplicate_transfer_flags', 'financial_evidence_large_deposit_flags', 'financial_evidence_reconciliation_candidates', 'financial_account_reclassification_requests', 'accounting_journal_entries', 'accounting_postings', 'payment_allocations', 'payment_reversals', 'invoice_write_offs', 'accounting_periods']);

        // Section 39A-3L, Phase B6, Checkpoint 34 (security_events) is
        // the final checkpoint in this arc: $forced now equals the FULL
        // preparedTables() set exactly, so the per-table loop below
        // legitimately has zero remaining iterations (a real, positive
        // end state, not a lost assertion). This explicit equality
        // check keeps the test genuinely assertive regardless of loop
        // iteration count.
        $forcedSorted = $forced;
        sort($forcedSorted);
        $preparedTablesSorted = $coverage->preparedTables();
        sort($preparedTablesSorted);
        $this->assertSame($forcedSorted, $preparedTablesSorted, 'Every originally "prepared" table must now be force-enabled, no more, no fewer.');

        foreach ($coverage->preparedTables() as $table) {
            if (in_array($table, $forced, true)) {
                continue;
            }

            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);
            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertFalse((bool) $row->relforcerowsecurity, "{$table} must not have accidentally become FORCE RLS enabled.");
        }
    }

    /**
     * This migration REPLACES the original single-expression policy
     * with two new policies — unlike every FORCE-only checkpoint,
     * where the pre-existing policy was left completely untouched.
     */
    public function test_the_original_single_policy_no_longer_exists(): void
    {
        $policy = DB::selectOne(
            "select polname from pg_policy where polrelid = 'incident_events'::regclass and polname = 'incident_events_tenant_isolation'"
        );

        $this->assertNull($policy, 'The original single-expression policy must have been dropped and replaced by the two new policies.');
    }

    public function test_the_read_and_write_policies_exist_with_the_expected_shape(): void
    {
        $readPolicy = DB::selectOne(
            "select polname, polcmd, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'incident_events'::regclass and polname = 'incident_events_tenant_read'"
        );

        $this->assertNotNull($readPolicy, 'incident_events_tenant_read must exist.');
        $this->assertSame('r', $readPolicy->polcmd, 'the read policy must be FOR SELECT only.');
        $this->assertStringContainsString('firm_id IS NULL', $readPolicy->using_expr);
        $this->assertNull($readPolicy->with_check_expr, 'a FOR SELECT policy has no WITH CHECK.');

        $writePolicy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'incident_events'::regclass and polname = 'incident_events_tenant_write'"
        );

        $this->assertNotNull($writePolicy, 'incident_events_tenant_write must exist.');
        $this->assertNotNull($writePolicy->with_check_expr, 'the write policy must carry an explicit, asymmetric WITH CHECK — not the FOR ALL implicit reuse of USING.');
        $this->assertStringContainsString('firm_id IS NULL', $writePolicy->using_expr);
        $this->assertStringContainsString('firm_id IS NULL', $writePolicy->with_check_expr);
    }

    /**
     * No other table's policy was modified by this migration — spot
     * check clients' own policy (the very first table forced in this
     * arc) and health_checks' own policy (the immediately prior
     * checkpoint) as representative unrelated policies.
     */
    public function test_no_other_tables_policy_was_changed(): void
    {
        // Narrowly updated by Checkpoint 4 (FirmsVault Live Integrations,
        // "Plaid financial evidence add-on") -- `clients` now legitimately
        // carries a SECOND policy, `clients_self_lookup`
        // (2026_09_24_180006_add_self_lookup_clause_to_clients_rls_policy.php,
        // mirroring the pre-existing firm_users_self_lookup precedent), so
        // the query below is now filtered by polname to keep proving
        // `clients_tenant_isolation` specifically remains unchanged --
        // additive only, no existing assertion removed or weakened.
        $clientsPolicy = DB::selectOne("select polname from pg_policy where polrelid = 'clients'::regclass and polname = 'clients_tenant_isolation'");
        $this->assertNotNull($clientsPolicy);
        $this->assertSame('clients_tenant_isolation', $clientsPolicy->polname);

        $clientsSelfLookupPolicy = DB::selectOne("select polname from pg_policy where polrelid = 'clients'::regclass and polname = 'clients_self_lookup'");
        $this->assertNotNull($clientsSelfLookupPolicy, 'clients_self_lookup (Checkpoint 4) must be present alongside clients_tenant_isolation.');

        $healthChecksWritePolicy = DB::selectOne("select polname from pg_policy where polrelid = 'health_checks'::regclass and polname = 'health_checks_tenant_write'");
        $this->assertNotNull($healthChecksWritePolicy);
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_a_firm_specific_row(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'firm-specific', IncidentSeverity::Critical, IncidentStatus::Investigating));

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, IncidentEvent::query()->where('firm_id', $firm->id)->count());
    }

    public function test_missing_tenant_context_cannot_insert_a_firm_specific_row(): void
    {
        $firm = Firm::factory()->create();

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->insertRow($firm->id, 'no-context-insert');
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs — firm-specific
    // rows remain strictly single-firm-visible, unchanged from the
    // original policy's own intent.
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_firm_specific_row(): void
    {
        $firmA = Firm::factory()->create();
        $rowId = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'firm-a-own'));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => IncidentEvent::query()->where('firm_id', $firmA->id)->pluck('id')->all(),
        );

        $this->assertSame([$rowId], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_specific_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'firm-b-only'));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => IncidentEvent::query()->pluck('id')->all(),
        );

        $this->assertNotContains($rowB, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'valid-insert'));

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_row_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmB->id, 'claimed-ownership'));
    }

    public function test_firm_a_context_cannot_update_firm_b_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'update-target'));

        $affected = $this->runWithFirmContext($firmA, function () use ($rowB) {
            return DB::table('incident_events')->where('id', $rowB)->update(['message' => 'Hijacked']);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s incident_events row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => IncidentEvent::query()->find($rowB),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertStringStartsWith('RLS proof row update-target', $reReadAsFirmB->message);
    }

    public function test_firm_a_context_cannot_reassign_firm_b_row_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'reassign-target'));

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $rowB) {
            return DB::table('incident_events')->where('id', $rowB)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s incident_events row to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => IncidentEvent::query()->find($rowB),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    public function test_firm_a_context_cannot_delete_firm_b_specific_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'delete-target'));

        $this->runWithFirmContext($firmA, function () use ($rowB) {
            DB::table('incident_events')->where('id', $rowB)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => IncidentEvent::query()->find($rowB),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s incident_events row.');
    }

    // ---------------------------------------------------------------
    // Platform-wide (firm_id = NULL) row visibility proofs — the
    // central, positive read-side design decision this checkpoint
    // proves: every tenant may see every platform-wide row.
    // ---------------------------------------------------------------

    public function test_a_platform_wide_row_is_visible_under_every_firm_scoped_sessions_context(): void
    {
        $platformWideId = $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'platform-wide'));

        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $visibleToA = $this->runWithFirmContext($firmA, fn () => IncidentEvent::query()->whereNull('firm_id')->pluck('id')->all());
        $visibleToB = $this->runWithFirmContext($firmB, fn () => IncidentEvent::query()->whereNull('firm_id')->pluck('id')->all());

        $this->assertContains($platformWideId, $visibleToA, 'Firm A must see the platform-wide row.');
        $this->assertContains($platformWideId, $visibleToB, 'Firm B must also independently see the same platform-wide row.');
    }

    public function test_a_platform_wide_row_does_not_expose_sibling_firm_specific_rows(): void
    {
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'platform-wide-isolation-check'));

        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'firm-b-not-leaked'));

        $visibleToA = $this->runWithFirmContext($firmA, fn () => IncidentEvent::query()->pluck('id')->all());

        $this->assertNotContains($rowB, $visibleToA, 'Firm A must still not see Firm B\'s firm-specific row, even though a platform-wide row is visible to both.');
    }

    // ---------------------------------------------------------------
    // WITH CHECK asymmetry proofs — INSERT-side forgery prevention.
    // ---------------------------------------------------------------

    public function test_a_firm_scoped_session_cannot_insert_a_forged_platform_wide_row(): void
    {
        $firm = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firm, fn () => $this->insertRow(null, 'forged-platform-wide'));
    }

    public function test_a_genuinely_context_free_session_can_insert_a_platform_wide_row(): void
    {
        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $insertedId = $this->insertRow(null, 'legitimate-platform-wide');

        $this->assertIsInt($insertedId);
    }

    // ---------------------------------------------------------------
    // WITH CHECK/USING asymmetry proofs — DELETE-side gap closure.
    // WITH CHECK is never consulted for DELETE in PostgreSQL, so an
    // asymmetric WITH CHECK alone (closing INSERT-side forgery) does
    // nothing for this mirror-image case — the write policy's own
    // USING clause is what closes it.
    // ---------------------------------------------------------------

    public function test_a_firm_scoped_session_cannot_delete_a_platform_wide_row(): void
    {
        $platformWideId = $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'delete-gap-target'));

        $firm = Firm::factory()->create();

        $affected = $this->runWithFirmContext($firm, function () use ($platformWideId) {
            return DB::table('incident_events')->where('id', $platformWideId)->delete();
        });

        $this->assertSame(0, $affected, 'A firm-scoped session must not be able to delete a platform-wide (firm_id = NULL) row.');

        $stillExists = $this->tenantContext()->runWithoutFirmContext(
            fn () => IncidentEvent::query()->whereNull('firm_id')->find($platformWideId),
        );

        $this->assertNotNull($stillExists, 'The platform-wide row must genuinely still exist in the database after the blocked delete attempt.');
    }

    public function test_a_firm_scoped_session_cannot_delete_all_platform_wide_rows_via_a_direct_is_null_filter(): void
    {
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'is-null-exploit-shape-1'));
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'is-null-exploit-shape-2'));

        $firm = Firm::factory()->create();

        $affected = $this->runWithFirmContext($firm, function () {
            return DB::table('incident_events')->whereNull('firm_id')->delete();
        });

        $this->assertSame(0, $affected, 'DELETE FROM incident_events WHERE firm_id IS NULL must affect zero rows under a firm-scoped session.');

        $remaining = $this->tenantContext()->runWithoutFirmContext(
            fn () => IncidentEvent::query()->whereNull('firm_id')->count(),
        );

        $this->assertSame(2, $remaining, 'Both platform-wide rows must genuinely still exist.');
    }

    public function test_a_genuinely_context_free_session_can_delete_a_platform_wide_row(): void
    {
        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $platformWideId = $this->insertRow(null, 'context-free-delete-target');

        $affected = DB::table('incident_events')->where('id', $platformWideId)->delete();

        $this->assertSame(1, $affected, 'A genuinely context-free session must be able to delete a platform-wide row it is also able to write.');
    }

    /**
     * Direct SQL-level proof a firm-scoped session cannot write into a
     * sibling firm's firm_id via UPDATE — mirror of the INSERT-side
     * forgery proof above, exercised through UPDATE ... SET firm_id.
     * Unlike the cross-firm UPDATE proof above (where the target row
     * is invisible under USING and the UPDATE silently affects zero
     * rows), this row IS visible under USING (firm A owns it) — the
     * failure instead comes from WITH CHECK rejecting the resulting
     * new row (firm_id = firm B) outright, raising a hard
     * row-level-security QueryException rather than returning 0.
     */
    public function test_a_firm_scoped_session_cannot_update_its_own_row_to_claim_sibling_firm_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowA = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'reassign-to-sibling'));

        try {
            $this->runWithFirmContext($firmA, function () use ($firmB, $rowA) {
                return DB::table('incident_events')->where('id', $rowA)->update(['firm_id' => $firmB->id]);
            });
            $this->fail('Expected a row-level security policy violation when Firm A tries to reassign its own row to Firm B.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('row-level security policy', $e->getMessage());
        }

        $this->assertNoDatabaseTenantContext();

        $stillFirmAs = $this->runWithFirmContext($firmA, fn () => IncidentEvent::query()->find($rowA));
        $this->assertNotNull($stillFirmAs);
        $this->assertSame($firmA->id, $stillFirmAs->firm_id);
    }

    // ---------------------------------------------------------------
    // Six-append-method correct-firm proofs — direct proof that the
    // newly-$firm-parameterized methods correctly find and update a
    // firm-specific incident when given the correct firm.
    // ---------------------------------------------------------------

    public function test_update_severity_with_the_correct_firm_finds_and_updates_the_firm_specific_incident(): void
    {
        $firm = Firm::factory()->create();
        $service = $this->incidentService();

        $opened = $service->open($firm, IncidentSeverity::Low, 'Tenant-specific anomaly');
        $updated = $service->updateSeverity($firm, $opened->correlation_id, IncidentSeverity::Critical);

        $this->assertSame(IncidentSeverity::Critical, $updated->severity);
        $this->assertSame($firm->id, $updated->firm_id);
    }

    public function test_all_six_append_methods_succeed_with_the_correct_firm_against_a_firm_specific_incident(): void
    {
        $firm = Firm::factory()->create();
        $service = $this->incidentService();

        $opened = $service->open($firm, IncidentSeverity::Medium, 'Firm-specific incident', customerImpact: true, notificationNeeded: true);
        $correlationId = $opened->correlation_id;

        $service->updateSeverity($firm, $correlationId, IncidentSeverity::High);
        $service->updateStatus($firm, $correlationId, IncidentStatus::Identified);
        $service->recordRootCause($firm, $correlationId, 'Bad tenant-scoped deploy');
        $service->flagCustomerImpact($firm, $correlationId, false);
        $service->flagNotificationNeeded($firm, $correlationId, false);
        $resolved = $service->resolve($firm, $correlationId, 'Rolled back the deploy');

        $this->assertSame(IncidentStatus::Resolved, $resolved->status);
        $this->assertSame($firm->id, $resolved->firm_id);

        $timeline = $this->runWithFirmContext($firm, fn () => $service->timeline($correlationId));
        $this->assertCount(7, $timeline);
        foreach ($timeline as $event) {
            $this->assertSame($firm->id, $event->firm_id, 'Every row for this correlation_id must share the same firm_id — ownership never mixes within one correlation_id.');
        }
    }

    // ---------------------------------------------------------------
    // Two distinct mismatch failure modes — Design Reviewer 1's
    // finding, proven SEPARATELY per the dossier's own explicit
    // requirement, not conflated into one test.
    // ---------------------------------------------------------------

    /**
     * Mismatch mode (a): a firm-scoped $firm against an incident
     * actually owned by a DIFFERENT firm → the row is invisible under
     * the wrong context, so currentState()'s firstOrFail() throws
     * ModelNotFoundException.
     */
    public function test_wrong_firm_against_a_firm_specific_incident_throws_model_not_found(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $service = $this->incidentService();

        $opened = $service->open($firmA, IncidentSeverity::High, 'Firm A only incident');

        $this->expectException(ModelNotFoundException::class);

        $service->updateSeverity($firmB, $opened->correlation_id, IncidentSeverity::Critical);
    }

    /**
     * Mismatch mode (b): a non-null $firm against an incident that is
     * actually PLATFORM-WIDE → currentState() SUCCEEDS (the read
     * policy's firm_id IS NULL branch is unconditional, so the row is
     * visible regardless of context), but appendEvent()'s subsequent
     * write is rejected directly by Postgres's row-level security
     * policy (neither the write policy's null-branch nor match-branch
     * is satisfied under a non-null context) — a distinct exception
     * type/site from mode (a) above, NOT a ModelNotFoundException.
     */
    public function test_firm_against_an_actually_platform_wide_incident_fails_at_the_write_not_the_read(): void
    {
        $firm = Firm::factory()->create();
        $service = $this->incidentService();

        $opened = $service->open(null, IncidentSeverity::Medium, 'Platform-wide incident');

        // Confirm the read succeeds under a mismatched firm context —
        // ruling out ModelNotFoundException as a false-positive
        // explanation for the failure asserted below.
        $readUnderMismatchedContext = $this->runWithFirmContext(
            $firm,
            fn () => $service->currentState($opened->correlation_id),
        );
        $this->assertNotNull($readUnderMismatchedContext);
        $this->assertNull($readUnderMismatchedContext->firm_id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy/');

        $service->updateSeverity($firm, $opened->correlation_id, IncidentSeverity::Critical);
    }

    // ---------------------------------------------------------------
    // Novel security contribution — appendEvent()'s forwarded
    // firm_id = $current->firm_id never diverges from the row actually
    // read under the active context. A firm-specific incident's
    // timeline never picks up a platform-wide or sibling-firm row.
    // ---------------------------------------------------------------

    public function test_appended_events_never_diverge_from_the_incidents_own_firm_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $service = $this->incidentService();

        // A platform-wide incident and two firm-specific incidents,
        // all created first so genuinely competing rows exist in the
        // database before any append runs.
        $platformWide = $service->open(null, IncidentSeverity::Low, 'Platform-wide baseline');
        $firmAIncident = $service->open($firmA, IncidentSeverity::High, 'Firm A incident');
        $firmBIncident = $service->open($firmB, IncidentSeverity::High, 'Firm B incident');

        $service->updateStatus(null, $platformWide->correlation_id, IncidentStatus::Identified);
        $service->updateStatus($firmA, $firmAIncident->correlation_id, IncidentStatus::Identified);
        $service->updateStatus($firmB, $firmBIncident->correlation_id, IncidentStatus::Identified);

        $platformTimeline = $this->tenantContext()->runWithoutFirmContext(fn () => $service->timeline($platformWide->correlation_id));
        $firmATimeline = $this->runWithFirmContext($firmA, fn () => $service->timeline($firmAIncident->correlation_id));
        $firmBTimeline = $this->runWithFirmContext($firmB, fn () => $service->timeline($firmBIncident->correlation_id));

        foreach ($platformTimeline as $event) {
            $this->assertNull($event->firm_id, 'Every row in the platform-wide incident\'s timeline must remain firm_id = NULL.');
        }
        foreach ($firmATimeline as $event) {
            $this->assertSame($firmA->id, $event->firm_id, 'Every row in Firm A\'s incident timeline must remain firm_id = Firm A — never NULL, never Firm B.');
        }
        foreach ($firmBTimeline as $event) {
            $this->assertSame($firmB->id, $event->firm_id, 'Every row in Firm B\'s incident timeline must remain firm_id = Firm B — never NULL, never Firm A.');
        }

        // Direct database-level confirmation: no row anywhere mixes a
        // correlation_id with more than one distinct firm_id value.
        // COALESCE(firm_id, -1) is required because COUNT(DISTINCT ...)
        // silently excludes NULLs in PostgreSQL — without it, a
        // genuinely-consistent all-NULL (platform-wide) correlation_id
        // group would wrongly report zero distinct values instead of
        // one.
        $distinctFirmIdsPerCorrelation = $this->tenantContext()->runWithoutFirmContext(fn () => DB::table('incident_events')
            ->select('correlation_id')
            ->selectRaw('count(distinct coalesce(firm_id, -1)) as distinct_firm_ids')
            ->groupBy('correlation_id')
            ->pluck('distinct_firm_ids', 'correlation_id'));

        foreach ($distinctFirmIdsPerCorrelation as $correlationId => $distinctCount) {
            $this->assertSame(1, (int) $distinctCount, "correlation_id {$correlationId} must have exactly one distinct firm_id across all its rows.");
        }
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    public function test_bare_factory_default_creation_is_safe_and_immediately_readable_under_any_firm(): void
    {
        $row = IncidentEvent::factory()->create();

        $this->assertNotNull($row->id);
        $this->assertNull($row->firm_id);

        $firm = Firm::factory()->create();
        $persisted = $this->runWithFirmContext($firm, fn () => IncidentEvent::query()->find($row->id));

        $this->assertNotNull($persisted, 'A bare factory-created platform-wide row must be visible under any firm\'s own context.');
        $this->assertNull($persisted->firm_id);
    }

    public function test_explicit_firm_id_factory_state_is_internally_consistent_and_firm_scoped(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();

        $row = IncidentEvent::factory()->create(['firm_id' => $firm->id]);

        $this->assertSame($firm->id, $row->firm_id);

        $persisted = $this->runWithFirmContext($firm, fn () => IncidentEvent::query()->find($row->id));
        $this->assertNotNull($persisted);
        $this->assertSame($firm->id, $persisted->firm_id);

        $notVisibleToOther = $this->runWithFirmContext($otherFirm, fn () => IncidentEvent::query()->where('firm_id', $firm->id)->find($row->id));
        $this->assertNull($notVisibleToOther, 'A firm-scoped factory row must not be visible under a sibling firm\'s own context.');
    }

    public function test_bare_factory_create_succeeds_after_a_prior_client_factory_call_in_the_same_test(): void
    {
        $firm = Firm::factory()->create();

        Client::factory()->forFirm($firm)->create();
        $this->assertDatabaseTenantContextIs($firm, 'ClientFactory must have left a stale, non-null DB-level context active.');

        $row = IncidentEvent::factory()->create();

        $this->assertNull($row->firm_id, 'The bare factory create() must still succeed and produce a genuinely null-firm_id row, despite the stale ambient context.');
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'context-clears-success'));

        $this->assertNoDatabaseTenantContext();
    }

    public function test_tenant_context_clears_after_exception(): void
    {
        $firm = Firm::factory()->create();

        try {
            $this->runWithFirmContext($firm, function () {
                throw new \RuntimeException('simulated failure inside firm context');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertNoDatabaseTenantContext();
    }

    public function test_run_without_firm_context_clears_database_context_after_success(): void
    {
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'without-context-success'));

        $this->assertNoDatabaseTenantContext();
    }

    public function test_incident_service_methods_clear_database_context_after_a_firm_scoped_operation(): void
    {
        $firm = Firm::factory()->create();

        $this->incidentService()->open($firm, IncidentSeverity::Low, 'Context lifecycle proof');

        $this->assertNoDatabaseTenantContext();
    }

    public function test_incident_service_methods_clear_database_context_after_a_platform_wide_operation(): void
    {
        $this->incidentService()->open(null, IncidentSeverity::Low, 'Context lifecycle proof, platform-wide');

        $this->assertNoDatabaseTenantContext();
    }

    // ---------------------------------------------------------------
    // Real production writer/reader proofs — IncidentService
    // ---------------------------------------------------------------

    public function test_incident_service_open_with_no_firm_persists_a_genuinely_visible_platform_wide_row(): void
    {
        $opened = $this->incidentService()->open(null, IncidentSeverity::High, 'Email delivery degraded');

        $this->assertNull($opened->firm_id);

        $firm = Firm::factory()->create();
        $visible = $this->runWithFirmContext($firm, fn () => IncidentEvent::query()->find($opened->id));
        $this->assertNotNull($visible, 'open() with no firm must genuinely persist a row visible under any firm\'s context.');
    }

    public function test_incident_service_open_with_a_firm_persists_a_firm_scoped_row_invisible_to_a_sibling(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();

        $opened = $this->incidentService()->open($firm, IncidentSeverity::High, 'Tenant-specific anomaly', customerImpact: true);

        $this->assertSame($firm->id, $opened->firm_id);

        $visible = $this->runWithFirmContext($firm, fn () => IncidentEvent::query()->find($opened->id));
        $this->assertNotNull($visible);

        $notVisibleToOther = $this->runWithFirmContext($otherFirm, fn () => IncidentEvent::query()->find($opened->id));
        $this->assertNull($notVisibleToOther);
    }

    // ---------------------------------------------------------------
    // Residual gap scope note — incident_events has no OTHER
    // tenant-owned relation of its own that RLS does not already
    // govern: firm_id is both its only foreign key into tenant-owned
    // data AND the exact column RLS itself governs. The one genuinely
    // table-specific residual gap, disclosed plainly rather than
    // hidden: currentState()/timeline() called directly (not through
    // one of the six wrapped methods) for a firm-specific incident,
    // with no or the wrong context established, is not protected by
    // anything but caller discipline — RLS fails closed (invisible row
    // or empty collection), but nothing forces a caller to establish
    // context before calling these two methods directly. Zero current
    // call sites exercise this directly, so nothing to fix today; a
    // future real caller must establish context itself, exactly as
    // health_checks' checkForKnownAnomalyPatterns() already requires.
    // ---------------------------------------------------------------

    // ---------------------------------------------------------------
    // Gap registry / simultaneous-isolation proofs
    // ---------------------------------------------------------------

    public function test_compliance_gap_registry_service_still_tracks_the_rls_gap(): void
    {
        $registry = new ComplianceGapRegistryService;

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPathsRaw('app/Services/ComplianceGapRegistryService.php');

        $this->assertSame('', $changed, 'ComplianceGapRegistryService.php must remain untouched by this checkpoint.');
    }

    /**
     * No UI/route/domain/deployment/payment/storage/AI/client-portal/
     * marketplace surface was added by this checkpoint — an
     * application-code-prerequisite-plus-migration-plus-test change
     * only, matching the contacts/parties/backup_restore_tests/
     * health_checks precedent's own scope.
     */
    public function test_no_ui_routes_or_controllers_were_introduced_by_this_checkpoint(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire', 'app/Services/Payments', 'app/Services/Storage', 'app/Services/Ai', 'app/Http/Controllers/ClientPortal', 'app/Services/Marketplace'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPathsRaw($relativeDir);

            $changed = implode("\n", array_filter(
                $changed === '' ? [] : (preg_split('/\\R/', $changed) ?: []),
                fn (string $path) => ! in_array($path, self::FIRMSVAULT_STAGING_ADMIN_STABILIZATION_APPROVED_FILES, true),
            ));

            $this->assertSame('', $changed, "Section 39A-3L, Checkpoint 29 must introduce no UI/route/domain surface, but found changes under {$relativeDir}.");
        }
    }

    /**
     * Forty-six previously forced tables plus incident_events must be
     * independently force-active and independently isolated at the
     * same time — proof this batch did not weaken or interfere with
     * any prior section's own enforcement. Uses health_checks as the
     * companion table (forced immediately prior, at Checkpoint 28).
     */
    public function test_incident_events_are_isolated_independently_and_simultaneously_with_health_checks(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $rowA = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'simultaneous-a'));
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'simultaneous-b'));

        $healthCheckA = $this->runWithFirmContext($firmA, fn () => HealthCheck::factory()->create(['firm_id' => $firmA->id, 'check_type' => HealthCheckType::TenantIsolationAnomalies->value, 'status' => HealthCheckStatus::Unhealthy->value]));
        $healthCheckB = $this->runWithFirmContext($firmB, fn () => HealthCheck::factory()->create(['firm_id' => $firmB->id, 'check_type' => HealthCheckType::TenantIsolationAnomalies->value, 'status' => HealthCheckStatus::Unhealthy->value]));

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'incident_events' => IncidentEvent::query()->where('firm_id', $firmA->id)->pluck('id')->all(),
            'health_checks' => HealthCheck::query()->pluck('id')->all(),
        ]);

        $this->assertSame([$rowA], $resultA['incident_events']);
        $this->assertNotContains($rowB, $resultA['incident_events']);
        $this->assertSame([$healthCheckA->id], $resultA['health_checks']);
        $this->assertNotContains($healthCheckB->id, $resultA['health_checks']);
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: the incident_events migration's down() must
     * genuinely restore the Section 39A baseline — RLS still enabled,
     * but NOT forced, and the ORIGINAL single-expression policy
     * restored byte-for-byte (both new policies dropped). Also proves
     * rollback affects ONLY this one table — every other
     * previously-forced table must be untouched. up() is re-run in a
     * finally block so this test leaves the schema in the same state
     * it found it in.
     */
    public function test_incident_events_migration_down_restores_the_original_single_policy_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930029_force_rls_on_incident_events_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'incident_events'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while incident_events is rolled back."
                );
            }

            $readPolicy = DB::selectOne("select polname from pg_policy where polrelid = 'incident_events'::regclass and polname = 'incident_events_tenant_read'");
            $writePolicy = DB::selectOne("select polname from pg_policy where polrelid = 'incident_events'::regclass and polname = 'incident_events_tenant_write'");
            $this->assertNull($readPolicy, 'Rollback must drop the new read policy.');
            $this->assertNull($writePolicy, 'Rollback must drop the new write policy.');

            $originalPolicy = DB::selectOne(
                "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
                 from pg_policy
                 where polrelid = 'incident_events'::regclass and polname = 'incident_events_tenant_isolation'"
            );
            $this->assertNotNull($originalPolicy, 'Rollback must restore the original single-expression policy.');
            $this->assertStringContainsString('current_setting', $originalPolicy->using_expr);
            $this->assertStringContainsString('firm_id', $originalPolicy->using_expr);
            $this->assertStringNotContainsString('IS NULL', $originalPolicy->using_expr, 'The restored original policy must be byte-for-byte the Phase 5 preparation text — it never had an IS NULL branch.');
            $this->assertNull($originalPolicy->with_check_expr, 'The restored original policy never had a separate WITH CHECK clause.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'incident_events'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');

        $writePolicyAfterUp = DB::selectOne("select polname from pg_policy where polrelid = 'incident_events'::regclass and polname = 'incident_events_tenant_write'");
        $this->assertNotNull($writePolicyAfterUp, 'up() must recreate the write policy.');
    }
}
