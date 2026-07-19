<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * TestCoverageMappingService — declares the master plan's Section 28
 * required test groups (24 keys) and maps each to the EXISTING test
 * evidence found in the real repository, or the explicit absence of
 * it. Purely declarative — no new testing framework, no new test
 * runner, no schema change. Reuses GovernanceMappingResult/
 * GovernanceMappingStatus from the Section 25-27 cross-cutting
 * package.
 *
 * Every classification below was determined by direct inspection of
 * the real repository's tests/Feature tree (149 directories, 360 test
 * files) at the time this service was written — not assumed from the
 * master plan alone. Several classifications deviate from the
 * "expected unless AWS proves otherwise" defaults because AWS
 * evidence proved otherwise; see each item's notes for the exact
 * finding.
 */
class TestCoverageMappingService
{
    public function __construct(
        private RowLevelSecurityCoverageMappingService $rlsCoverage = new RowLevelSecurityCoverageMappingService(),
    ) {
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function all(): array
    {
        $uncoveredCount = count($this->rlsCoverage->missingPreparedTables());

        return [
            new GovernanceMappingResult(
                item_key: 'tenant_isolation_general',
                item_label: 'General tenant isolation (application-layer query scoping)',
                owning_class: \App\Models\Concerns\BelongsToTenant::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'tests/Feature/Tenancy/BelongsToTenantScopeTest.php (9 tests) exercises the BelongsToTenant global scope directly.',
            ),
            new GovernanceMappingResult(
                item_key: 'tenant_isolation_broken_scope_caught_by_rls',
                item_label: 'A broken/bypassed application-layer query scope is still caught by database-level row-level security',
                owning_class: \App\Services\RowLevelSecurityCoverageMappingService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: "FORCE ROW LEVEL SECURITY and SET LOCAL app.current_firm_id middleware are now genuinely active for every originally-inventoried tenant-owned table (Section 39A-5's 60-table rollout closed the last remaining uncovered tables in Wave 11 — {$uncoveredCount} tenant-owned tables now uncovered, down from 61 at the start of that rollout — see tests/Feature/Security/RlsForceRollout/*ForceRlsActivationTest.php) — a broken/bypassed application-layer scope against any of those tables IS now actually caught at the database layer. Still PartiallyImplemented, not Implemented, for reasons unrelated to table coverage: offboarding_exports remains an open, uncertain-ownership investigation not counted above; the cross-firm-pivot-mismatch remediation task for matter_parties/matter_assignments/task_dependencies/matters/invoices/payments is still unimplemented (RLS on the parent tables alone is explicitly not accepted as sufficient); the firms root-tenant-table policy and the support-access policy shape for support_access_requests/support_access_sessions are both still registered designs only, not implemented. See docs/governance/rls-gap-registry.md for the authoritative, current list of what remains open.",
            ),
            new GovernanceMappingResult(
                item_key: 'role_permission_org_boundaries',
                item_label: 'Role/permission/organization boundary enforcement',
                owning_class: \App\Services\PermissionMatrixMappingService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'tests/Feature/Governance/PermissionBoundaries/ (8 files) covers firm/platform role boundaries thoroughly. Org-level boundaries cannot be fully tested because org_admin does not exist as a role at all — see the org_admin_role_missing gap. Not Implemented while that role remains missing.',
            ),
            new GovernanceMappingResult(
                item_key: 'entitlement_inheritance_override_precedence',
                item_label: 'Entitlement inheritance and override precedence',
                owning_class: \App\Services\EntitlementService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'tests/Feature/Entitlements/: EntitlementServiceTest, EntitlementOverrideServiceTest, EntitlementPlanSyncServiceTest, FirmEntitlementTest, FirmEntitlementEventTest all exist and exercise precedence directly.',
            ),
            new GovernanceMappingResult(
                item_key: 'client_portal_access',
                item_label: 'Client portal access control',
                owning_class: \App\Services\ClientPortalService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'ClientPortalService exists with real logic and tests/Feature/Clients/ClientPortalServiceTest.php exercises it, but no login/session/auth HTTP layer exists for a client to actually reach the portal — the backend service is tested, the access surface it would gate is not built yet.',
            ),
            new GovernanceMappingResult(
                item_key: 'conflict_check_firm_default_org_opt_in',
                item_label: 'Conflict checking: firm-scoped by default, organization-wide only by explicit opt-in',
                owning_class: \App\Services\ConflictCheckService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'tests/Feature/Conflicts/ConflictCheckServiceTest.php exists and exercises ConflictCheckService::resolveScope() default/opt-in behavior.',
            ),
            new GovernanceMappingResult(
                item_key: 'consent_enforcement_sms_whatsapp_email',
                item_label: 'Consent enforcement across SMS, WhatsApp, and email channels',
                owning_class: \App\Services\ConsentService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'tests/Feature/Activation/ConsentServiceTest.php explicitly exercises ConsentChannel::Sms, ConsentChannel::Email, and ConsentChannel::WhatsApp — all three required channels are tested, not just one.',
            ),
            new GovernanceMappingResult(
                item_key: 'document_security_virus_scanning',
                item_label: 'Document security and virus scanning gate',
                owning_class: \App\Services\DocumentSecurityService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'tests/Feature/Documents/DocumentSecurityServiceTest.php covers infected/failed/clean scan outcomes and the resulting document status transitions. The gate itself is real and tested; the scanning engine behind it (FakeVirusScanner) is a stub — see the real_malware_scanning_engine_stubbed gap.',
            ),
            new GovernanceMappingResult(
                item_key: 'upload_download_authorization',
                item_label: 'Upload/download authorization',
                owning_class: \App\Services\DocumentUploadPolicyService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'tests/Feature/Documents/DocumentUploadPolicyServiceTest.php, tests/Feature/Pdf/PdfDownloadPolicyServiceTest.php, and tests/Feature/Forms/Review/FormAndDocumentAccessPolicyServiceTest.php all exist and exercise authorization directly.',
            ),
            new GovernanceMappingResult(
                item_key: 'payment_classification',
                item_label: 'Payment classification (trust vs operating)',
                owning_class: \App\Services\PaymentClassificationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'tests/Feature/Payments/PaymentClassificationServiceTest.php and tests/Feature/Phase7Reuse/PaymentClassificationStillBlockedAfterPhase13Test.php both exist.',
            ),
            new GovernanceMappingResult(
                item_key: 'payment_plan_lifecycle',
                item_label: 'Payment plan lifecycle: creation, installment collection, missed-installment dunning, renegotiation',
                owning_class: \App\Services\PaymentPlanService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'All four sub-requirements have dedicated test coverage: PaymentPlanServiceTest (creation + renegotiation), PaymentPlanInstallmentServiceTest (installment collection), PaymentPlanDunningServiceTest (missed-installment dunning), PaymentPlanTest/PaymentPlanInstallmentTest (model behavior).',
            ),
            new GovernanceMappingResult(
                item_key: 'manual_payment_double_submit',
                item_label: 'Manual payment double-submission protection',
                owning_class: \App\Services\ManualPaymentService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'tests/Feature/Payments/ManualPaymentServiceTest.php includes test_manual_double_submission_with_the_same_idempotency_key_does_not_create_duplicate_payments, test_double_submission_replays_the_original_blocked_outcome_rather_than_retrying, and test_the_database_unique_index_prevents_two_payments_sharing_an_idempotency_key_for_the_same_firm.',
            ),
            new GovernanceMappingResult(
                item_key: 'stripe_payment_webhook_idempotency',
                item_label: 'Payment provider webhook idempotency',
                owning_class: \App\Models\Payment::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'No real Stripe (or any other payment provider) integration exists anywhere in the repository (zero "Stripe"-named files). The underlying idempotency mechanism this item is really about is payments.idempotency_key + its unique index, and its webhook-level effect IS tested: PaymentRecordedWiringTest::test_a_repeated_idempotency_key_does_not_fire_a_second_webhook_event. Partial because there is no real provider webhook consumer to test end-to-end.',
            ),
            new GovernanceMappingResult(
                item_key: 'platform_billing_separation_consolidation_usage_attribution',
                item_label: 'Platform billing separation, consolidation, and usage attribution',
                owning_class: \App\Services\UsageRollupService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'tests/Feature/PlatformBilling/PlatformBillingSeparationTest.php plus PlatformSubscriptionServiceTest, PlatformInvoiceServiceTest, PlatformPaymentServiceTest, PlatformRefundServiceTest, and tests/Feature/UsageRollups/UsageRollupServiceTest.php all exist.',
            ),
            new GovernanceMappingResult(
                item_key: 'trust_ledger_concurrency',
                item_label: 'Trust ledger concurrency safety',
                owning_class: \App\Services\TrustLedgerService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'tests/Feature/Trust/Concurrency/TrustConcurrencyLockServiceTest.php exists and exercises concurrent-access locking directly.',
            ),
            new GovernanceMappingResult(
                item_key: 'legal_specialist_trust_route_blocking',
                item_label: 'legal_specialist customers blocked from trust/IOLTA routes',
                owning_class: \App\Services\LegalSpecialistBoundaryPolicyService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'tests/Feature/Deployment/LegalSpecialist/LegalSpecialistBoundaryPolicyServiceTest.php exists and exercises assertTrustIoltaNeverEnabledFor() directly.',
            ),
            new GovernanceMappingResult(
                item_key: 'ai_permission_approval_retrieval_prompt_injection',
                item_label: 'AI matter permission, approval workflow, retrieval isolation, and prompt-injection resistance',
                owning_class: \App\Services\AiRetrievalIsolationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'tests/Feature/Ai/ has dedicated Retrieval/, Approval/, PromptInjection/, Entitlement/, and Firewall/ subdirectories, each with real test coverage.',
            ),
            new GovernanceMappingResult(
                item_key: 'email_deliverability_gate',
                item_label: 'Email deliverability gate (no bypass of consent/suppression)',
                owning_class: \App\Services\EmailDeliverabilityNonBypassGuard::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'EmailDeliverabilityNonBypassGuard exists with real reflection-based structural checks, but a repository-wide case-insensitive search for "deliverability" across tests/ found ZERO matches — no test file exercises this guard at all today. The mechanism exists in code; it is currently unverified by any test.',
            ),
            new GovernanceMappingResult(
                item_key: 'queue_scheduler_health',
                item_label: 'Queue and scheduler health monitoring',
                owning_class: \App\Services\QueueHealthService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'QueueHealthService reads the real jobs/failed_jobs tables (pendingJobsCount()/failedJobsCount()); SchedulerHealthService checks real heartbeat staleness via cache. Both have dedicated tests (QueueHealthServiceTest, SchedulerHealthServiceTest) — this is real functional health-check logic, not merely a declarative readiness stub.',
            ),
            new GovernanceMappingResult(
                item_key: 'import_export_tenant_isolation',
                item_label: 'Import/export tenant isolation',
                owning_class: \App\Services\ImportBatchService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'tests/Feature/TenantIsolation/ImportExportTenantIsolationTest.php exists and exercises this directly.',
            ),
            new GovernanceMappingResult(
                item_key: 'template_versioning_form_edition_retirement',
                item_label: 'Template versioning and form-edition retirement',
                owning_class: \App\Services\TemplateUpgradeLogService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'tests/Feature/Templates/TemplateUpgradeLogServiceTest.php, TemplateUpgradePreviewServiceTest.php, and tests/Feature/Forms/Watch/FormEditionWatchServiceTest.php all exist.',
            ),
            new GovernanceMappingResult(
                item_key: 'fleet_migration_offline_license_validation',
                item_label: 'Fleet migration rehearsal and offline license validation',
                owning_class: \App\Services\FleetMigrationOrchestrationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'tests/Feature/Deployment/Fleet/FleetMigrationOrchestrationServiceTest.php and tests/Feature/Deployment/License/LicenseFileSigningAndValidationServiceTest.php both exist.',
            ),
            new GovernanceMappingResult(
                item_key: 'restore_tests',
                item_label: 'Backup/restore drill testing',
                owning_class: \App\Services\BackupRestoreTestService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'tests/Feature/BackupRestore/BackupRestoreTestServiceTest.php exists, but BackupRestoreTestService\'s own docblock states it "never performs a real infrastructure backup/restore" — it only records the result of whatever BackupRestoreDrillRunner it is given, and FakeBackupRestoreDrillRunner is the only implementation exercised in tests. Readiness-only; does not exercise a real restore path — see the restore_tests_do_not_exercise_real_restore_path gap.',
            ),
            new GovernanceMappingResult(
                item_key: 'accessibility_client_facing_flows',
                item_label: 'Accessibility checks for client-facing flows',
                owning_class: \App\Services\AccessibilityCoverageMappingService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'Implemented as a readiness checklist, not as browser/rendered-UI accessibility testing — no client-facing UI is rendered anywhere in the repository yet. BillingAccessibilityReadinessServiceTest, FormAccessibilityReadinessServiceTest, SignatureAccessibilityReadinessServiceTest, ClientPortalAccessibilityReadinessServiceTest, and AccessibilityCoverageMappingServiceTest all exist and are real, passing tests of the readiness checklists themselves.',
            ),
        ];
    }

    public function byKey(string $key): ?GovernanceMappingResult
    {
        foreach ($this->all() as $item) {
            if ($item->item_key === $key) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function implemented(): array
    {
        return $this->byStatus(GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function partial(): array
    {
        return $this->byStatus(GovernanceMappingStatus::PartiallyImplemented);
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function notFound(): array
    {
        return $this->byStatus(GovernanceMappingStatus::NotFound);
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function notApplicableYet(): array
    {
        return $this->byStatus(GovernanceMappingStatus::NotApplicableYet);
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    private function byStatus(GovernanceMappingStatus $status): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => $item->status === $status,
        ));
    }
}
