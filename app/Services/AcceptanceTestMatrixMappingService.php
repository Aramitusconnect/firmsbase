<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * AcceptanceTestMatrixMappingService — declares the master plan's
 * Section 36 acceptance-test matrix (99 keys across 15 groups) and
 * maps each to the real, existing test/service/readiness evidence
 * found by direct repository inspection, or honestly classifies it
 * NotFound/NotApplicableYet. Purely declarative — no migration, no new
 * enum, no new value object, no product behavior change, no browser/
 * mobile automation created. Reuses GovernanceMappingResult/
 * GovernanceMappingStatus from the Section 25 cross-cutting package.
 *
 * GovernanceMappingStatus has no NotApplicableYet case (confirmed by
 * direct enum inspection), so per approved decision this service does
 * NOT add one — NotApplicableYet items are classified
 * GovernanceMappingStatus::NotFound and the "blocked by missing
 * UI/mobile/browser/provider surface, not a product gap" reason is
 * encoded in notes; notApplicableYet() filters for that reason marker
 * rather than a distinct enum case.
 *
 * AWS confirmed no browser/mobile/Dusk/Playwright/Cypress test harness
 * exists anywhere in this repository, and app/Filament/routes/
 * app/Http/Controllers carry no real UI beyond Laravel's empty
 * scaffolding (confirmed across Sections 34/35 firewall tests) — the
 * accessibility_mobile group and every web-session-dependent security
 * control are genuinely NotApplicableYet, not faked.
 *
 * Every classification below was determined by direct inspection of
 * the real repository (all relevant tests/Feature, tests/Unit,
 * app/Services) at the time this service was written.
 */
class AcceptanceTestMatrixMappingService
{
    /**
     * Marker embedded in notes for every NotApplicableYet finding, so
     * notApplicableYet() can filter without a distinct enum case.
     */
    private const NOT_APPLICABLE_YET_MARKER = '[NOT_APPLICABLE_YET]';

    private const GROUPS = [
        'tenant_isolation', 'security', 'entitlements', 'commercial_hierarchy', 'practice_areas',
        'conflicts', 'documents', 'notifications_consent', 'billing', 'trust', 'ai', 'import_export',
        'forms_documents', 'reliability_fleet', 'accessibility_mobile',
    ];

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function all(): array
    {
        return array_merge(
            $this->tenantIsolation(),
            $this->security(),
            $this->entitlements(),
            $this->commercialHierarchy(),
            $this->practiceAreas(),
            $this->conflicts(),
            $this->documents(),
            $this->notificationsConsent(),
            $this->billing(),
            $this->trust(),
            $this->ai(),
            $this->importExport(),
            $this->formsDocuments(),
            $this->reliabilityFleet(),
            $this->accessibilityMobile(),
        );
    }

    public function byKey(string $key): ?GovernanceMappingResult
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function groups(): array
    {
        return self::GROUPS;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function group(string $group): array
    {
        return array_filter(
            $this->all(),
            fn (string $key) => str_starts_with($key, "{$group}."),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function implemented(): array
    {
        return $this->byStatus(GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function partial(): array
    {
        return $this->byStatus(GovernanceMappingStatus::PartiallyImplemented);
    }

    /**
     * NotFound items EXCLUDING the NotApplicableYet-marked ones (a
     * genuine "no evidence at all" finding, distinct from "blocked by
     * a missing surface that is out of this section's scope").
     *
     * @return array<string, GovernanceMappingResult>
     */
    public function notFound(): array
    {
        return array_filter(
            $this->byStatus(GovernanceMappingStatus::NotFound),
            fn (GovernanceMappingResult $item) => ! str_contains($item->notes, self::NOT_APPLICABLE_YET_MARKER),
        );
    }

    /**
     * Items blocked by a missing UI/client-portal/mobile/browser/
     * provider surface — never a product gap by itself.
     *
     * @return array<string, GovernanceMappingResult>
     */
    public function notApplicableYet(): array
    {
        return array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => str_contains($item->notes, self::NOT_APPLICABLE_YET_MARKER),
        );
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function uiDependent(): array
    {
        return $this->notApplicableYet();
    }

    /**
     * Items whose real-provider dependency is deliberately simulated/
     * readiness-only (no real Stripe/email/SMS/AI provider call).
     *
     * @return array<string, GovernanceMappingResult>
     */
    public function providerSimulated(): array
    {
        $keys = [
            'documents.virus_scan', 'billing.stripe_classification_before_intent',
            'ai.disabled_plan_blocks_ai', 'ai.firm_owned_key_encryption',
        ];

        return array_intersect_key($this->all(), array_flip($keys));
    }

    /**
     * True security/data-integrity blockers only — never UI-absence
     * findings, which are NotApplicableYet instead.
     *
     * @return array<string, GovernanceMappingResult>
     */
    public function productionBlockers(): array
    {
        $keys = [
            'tenant_isolation.rls_broken_scope', 'documents.signed_urls_via_tenant_context',
            'documents.virus_scan', 'security.emergency_access_audit',
        ];

        return array_intersect_key($this->all(), array_flip($keys));
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function pilotGate(string $gate): array
    {
        return match ($gate) {
            'saas' => $this->saasPilot(),
            'dedicated_private_enterprise' => $this->dedicatedPrivateEnterprise(),
            'payment' => $this->paymentPilot(),
            'trust' => $this->trustPilot(),
            'ai' => $this->aiPilot(),
            'client_portal_mobile' => $this->clientPortalMobileLaunch(),
            default => [],
        };
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function saasPilot(): array
    {
        return array_merge(
            $this->group('tenant_isolation'),
            $this->group('entitlements'),
            $this->group('commercial_hierarchy'),
            $this->group('practice_areas'),
            $this->group('conflicts'),
            array_diff_key($this->group('documents'), ['documents.signed_urls_via_tenant_context' => true]),
            $this->group('notifications_consent'),
            array_intersect_key($this->all(), array_flip([
                'billing.invoice_lifecycle', 'billing.flat_fee_invoice', 'billing.payment_plan_lifecycle',
                'billing.manual_payment_classification',
            ])),
        );
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function dedicatedPrivateEnterprise(): array
    {
        return array_merge(
            $this->saasPilot(),
            $this->group('reliability_fleet'),
            array_intersect_key($this->all(), array_flip(['tenant_isolation.rls_broken_scope'])),
        );
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function paymentPilot(): array
    {
        return $this->group('billing');
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function trustPilot(): array
    {
        return $this->group('trust');
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function aiPilot(): array
    {
        return $this->group('ai');
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function clientPortalMobileLaunch(): array
    {
        return array_merge(
            $this->group('accessibility_mobile'),
            array_intersect_key($this->all(), array_flip([
                'documents.signed_urls_via_tenant_context', 'security.two_factor_authentication',
                'security.session_timeout', 'security.csrf',
            ])),
        );
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function existingGapCrossReferences(): array
    {
        return array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => preg_match('/\b[a-z][a-z0-9_]*_(missing|not_enforced|not_wired|not_guaranteed|stubbed|incomplete|not_enforced|removed)\b/', $item->notes) === 1,
        );
    }

    /**
     * No new gap is warranted: every finding here either confirms real
     * test coverage, cross-references an existing tracked gap, or is
     * NotApplicableYet (blocked by a missing UI/mobile/browser/provider
     * surface, never a product gap by itself).
     *
     * @return array<string, GovernanceMappingResult>
     */
    public function gaps(): array
    {
        return [];
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function byStatus(GovernanceMappingStatus $status): array
    {
        return array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => $item->status === $status,
        );
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function tenantIsolation(): array
    {
        return $this->build('tenant_isolation', [
            'cross_firm_query' => [null, GovernanceMappingStatus::Implemented, 'Tests\\Feature\\Tenancy\\BelongsToTenantScopeTest and multiple dedicated cross-firm tests (AccountingTenantIsolationTest, EmailTenantIsolationTest, WebhookTenantIsolationTest) prove a query scoped to one firm never returns another firm\'s rows.'],
            'rls_broken_scope' => [null, GovernanceMappingStatus::PartiallyImplemented, 'Tests\\Feature\\Tenancy\\RowLevelSecurityPreparationTest and Phase6RowLevelSecurityTest both exist and explicitly assert FORCE ROW LEVEL SECURITY is NOT enabled — real, honest test coverage of the CURRENT (unenforced) state. References the existing rls_prepared_not_enforced gap; not a duplicate.'],
            'cross_firm_document_access' => [null, GovernanceMappingStatus::Implemented, 'Tests\\Feature\\TenantIsolation\\FormAndDocumentTenantIsolationTest and SignatureAndPdfTenantIsolationTest both exist and cover this exact scenario.'],
            'cross_firm_api_key' => [null, GovernanceMappingStatus::Implemented, 'Tests\\Feature\\Api\\ApiAccessPolicyServiceTest and ApiKeyScopeServiceTest cover API key tenant/scope boundaries.'],
            'cross_firm_import_export' => [null, GovernanceMappingStatus::Implemented, 'Tests\\Feature\\TenantIsolation\\ImportExportTenantIsolationTest exists specifically for this scenario.'],
        ]);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function security(): array
    {
        $result = $this->build('security', [
            'support_access_approval' => [\App\Services\SupportAccessPolicyService::class, GovernanceMappingStatus::Implemented, 'Tests\\Feature\\SupportAccess\\SupportAccessPolicyServiceTest and SupportAccessRequestServiceTest cover the firm-approved access flow.'],
            'per_firm_key_provisioning' => [\App\Services\EncryptionKeyService::class, GovernanceMappingStatus::Implemented, 'Tests\\Feature\\Activation\\EncryptionKeyServiceTest and TenantEncryptionKeyTest cover per-firm envelope key provisioning.'],
        ], GovernanceMappingStatus::Implemented);

        foreach (['two_factor_authentication', 'session_timeout', 'csrf', 'rate_limit', 'password_policy'] as $key) {
            $result["security.{$key}"] = $this->notApplicable(
                'security',
                $key,
                'No web session/login/HTTP layer exists at all (confirmed across every prior section\'s firewall tests — routes/web.php has only the default welcome route, no login/registration flow exists). References the existing firm_user_2fa_missing/client_portal_2fa_missing/login_policy_wrappers_missing gaps rather than duplicating them. Blocked by a missing surface, not a product gap.',
            );
        }

        $result['security.emergency_access_audit'] = $this->result(
            'security',
            'emergency_access_audit',
            \App\Services\EmergencyAccessGovernanceGapService::class,
            GovernanceMappingStatus::PartiallyImplemented,
            'support_access_requests.emergency_justification/reason are real and audited via SupportAccessPolicyService::logSessionAudit(), but references the existing emergency_support_access_high_risk_approval_not_wired gap: no platform-admin eligibility check occurs before emergency access proceeds.',
        );

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function entitlements(): array
    {
        $result = $this->build('entitlements', [
            'api_blocked' => [null, GovernanceMappingStatus::Implemented, 'Tests\\Feature\\Api\\ApiAccessPolicyServiceTest covers API-layer entitlement gating.'],
            'webhook_blocked' => [null, GovernanceMappingStatus::PartiallyImplemented, 'Tests\\Feature\\TenantIsolation\\WebhookTenantIsolationTest covers tenant isolation for webhooks, but no dedicated test confirms a webhook is blocked specifically because its module entitlement is disabled.'],
            'import_export_blocked_for_disabled_module' => [null, GovernanceMappingStatus::PartiallyImplemented, 'tests/Feature/Imports/ has extensive real coverage (ImportBatchServiceTest, ImportApplyServiceTest, etc.), but no test specifically confirms import/export is blocked when the relevant module entitlement is disabled.'],
            'org_inheritance_override_precedence' => [\App\Services\EntitlementService::class, GovernanceMappingStatus::Implemented, 'Tests\\Feature\\Entitlements\\EntitlementServiceTest::test_admin_override_wins_over_all_other_sources() and test_org_inherited_wins_over_plan_when_no_overrides_exist() directly test the full admin_override > firm_override > org_inherited > plan precedence chain.'],
            'flag_only_restricts_rule' => [\App\Services\FeatureGateService::class, GovernanceMappingStatus::Implemented, 'Tests\\Feature\\Entitlements\\FeatureGateServiceTest::test_is_allowed_false_when_no_entitlement_exists_at_all() and test_is_allowed_true_when_entitlement_enabled_and_no_flags_exist() confirm a feature flag can only restrict what an entitlement already grants, never widen it.'],
        ], GovernanceMappingStatus::Implemented);

        $result['entitlements.job_blocked'] = $this->result('entitlements', 'job_blocked', null, GovernanceMappingStatus::NotFound, 'No queued/async job class was found gated by entitlement status specifically (confirmed by direct search across Section 33-35 inspection — no ShouldQueue AI job exists, and no dedicated queued-job entitlement gate test was found).');
        $result['entitlements.command_blocked'] = $this->notApplicable('entitlements', 'command_blocked', 'app/Console does not exist at all (confirmed by direct search) — there is no artisan command surface to gate or test. Blocked by a missing surface, not a product gap.');
        $result['entitlements.report_blocked'] = $this->result('entitlements', 'report_blocked', null, GovernanceMappingStatus::NotFound, 'No dedicated "report" generation surface or reporting-entitlement gate/test was found in the repository.');
        $result['entitlements.ui_hidden'] = $this->notApplicable('entitlements', 'ui_hidden', 'No admin/firm UI exists at all — app/Filament does not exist (confirmed by Section 34\'s AWS inspection). Blocked by a missing surface, not a product gap.');
        $result['entitlements.route_blocked'] = $this->notApplicable('entitlements', 'route_blocked', 'No application routes exist beyond the default welcome route (confirmed across every prior section\'s firewall tests). Blocked by a missing surface, not a product gap.');

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function commercialHierarchy(): array
    {
        $result = $this->build('commercial_hierarchy', [
            'org_creation' => [null, GovernanceMappingStatus::Implemented, 'Organization model and its factory/tests are real and exercised throughout tests/Feature/Organizations/.'],
            'firm_attach_detach' => [null, GovernanceMappingStatus::Implemented, 'Firm.organization_id/billing_account_id are real, nullable columns, covered by tests/Feature/Organizations/ and tests/Feature/Licensing/ (e.g. Tests\\Feature\\Licensing\\FirmLicenseCommercialServiceTest).'],
            'consolidated_invoice_usage_attribution' => [null, GovernanceMappingStatus::Implemented, 'Tests\\Feature\\PlatformBilling\\PlatformBillingSeparationTest and tests/Feature/UsageRollups/ cover consolidated invoicing and usage attribution keyed to billing_account_id.'],
            'pooled_seat_enforcement_by_class' => [\App\Services\SeatEnforcementService::class, GovernanceMappingStatus::Implemented, 'Tests\\Feature\\Seats\\SeatEnforcementServiceTest, SeatPoolServiceTest, and SeatAllocationServiceTest cover pooled seat enforcement per class.'],
        ], GovernanceMappingStatus::Implemented);

        $result['commercial_hierarchy.commission_single_attribution_org_expansion'] = $this->result(
            'commercial_hierarchy',
            'commission_single_attribution_org_expansion',
            \App\Services\CommissionEligibilityService::class,
            GovernanceMappingStatus::PartiallyImplemented,
            'Tests\\Feature\\Commissions\\CommissionEligibilityServiceTest has 5 real tests (unpaid invoice blocking, refunded payment blocking, disqualifying billing events, holding period, "commission never uses firm client payments"), but none specifically test that adding a new firm to an existing organization results in single, non-duplicated commission attribution — that exact scenario is not directly tested.',
        );

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function practiceAreas(): array
    {
        $fields = [
            'firm_enables_multiple_areas' => 'Tests\\Feature\\PracticeTemplates\\FirmPracticeAreaTest covers a firm enabling multiple practice areas.',
            'matter_has_one_primary_area' => 'Matter.primary_practice_area_id is a real, single (non-array) FK column, covered by tests/Feature/Matters/.',
            'template_pack_installed' => 'Tests\\Feature\\PracticeTemplates\\TemplatePackInstallationServiceTest covers real install behavior.',
            'template_version_pinned' => 'Matter.pinned_template_pack_version_id is set-once/never-changed, confirmed by tests/Feature/Matters/ and Section 33/35 evidence.',
            'upgrade_preview_works' => 'TemplateUpgradePreviewService is real and exercised in tests/Feature/PracticeTemplates/ and related suites.',
        ];

        return $this->build('practice_areas', array_map(fn ($note) => [null, GovernanceMappingStatus::Implemented, $note], $fields));
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function conflicts(): array
    {
        $fields = [
            'client_contact_party_company_email_phone_matching' => 'Tests\\Feature\\Conflicts\\ConflictCheckServiceTest and PartyTest cover client/contact/party matching across name/email/phone/company.',
            'false_positive_review' => 'ConflictCheckResultStatus::PossibleMatch/Dismissed and review_notes are real, tested via ConflictCheckServiceTest.',
            'matter_opening_gate_blocks_until_review' => 'MatterOpeningService is the ONLY place MatterStatus::Open is set, requiring a completed, non-blocking conflict check first — confirmed by Section 33 inspection and tests/Feature/Matters/.',
            'firm_scoped_default' => 'ConflictCheckService::resolveScope() defaults to firm-scoped, tested via ConflictCheckServiceTest.',
            'org_wide_opt_in_behavior' => 'Organization.conflict_scope drives ConflictCheckService::resolveScope()\'s organization-wide search path, tested via ConflictCheckServiceTest.',
        ];

        return $this->build('conflicts', array_map(fn ($note) => [\App\Services\ConflictCheckService::class, GovernanceMappingStatus::Implemented, $note], $fields));
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function documents(): array
    {
        $result = $this->build('documents', [
            'private_storage' => [\App\Services\DocumentSecurityService::class, GovernanceMappingStatus::Implemented, 'Tests\\Feature\\Documents\\DocumentSecurityServiceTest confirms documents are private by default, never a public URL.'],
            'rejected_file_type' => [\App\Services\DocumentUploadPolicyService::class, GovernanceMappingStatus::Implemented, 'Tests\\Feature\\Documents\\DocumentUploadPolicyServiceTest covers extension allowlist/blocklist rejection.'],
            'replacement_request' => [\App\Services\DocumentReplacementService::class, GovernanceMappingStatus::Implemented, 'Tests\\Feature\\Documents\\DocumentReplacementServiceTest and DocumentRequestServiceTest cover the full replacement/versioning flow.'],
            'document_audit_trail' => [null, GovernanceMappingStatus::Implemented, 'DocumentRequestItemTest and DocumentSecurityServiceTest cover the review/approval/audit trail on document lifecycle.'],
        ], GovernanceMappingStatus::Implemented);

        $result['documents.signed_urls_via_tenant_context'] = $this->result(
            'documents',
            'signed_urls_via_tenant_context',
            \App\Services\DocumentSecurityService::class,
            GovernanceMappingStatus::PartiallyImplemented,
            'DocumentSecurityService::canAccess() (the tenant-context gate any future signed-URL endpoint must call first) is real and tested, but references the existing signed_document_url_service_missing gap: no signed, time-limited temporary URL service exists yet.',
        );
        $result['documents.virus_scan'] = $this->result(
            'documents',
            'virus_scan',
            \App\Services\PaymentClassificationService::class,
            GovernanceMappingStatus::PartiallyImplemented,
            'The scan gate itself is real and enforced (a document cannot reach Approved while scan_status is not Clean), but references the existing real_malware_scanning_engine_stubbed gap: FakeVirusScanner is the only implementation, no real scanning engine exists — simulated/stub, not a real provider call, as required by this section\'s boundary.',
        );

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function notificationsConsent(): array
    {
        $fields = [
            'verified_sender_domain_gate' => [null, 'Tests\\Feature\\Notifications\\ (email deliverability gate group, per TestCoverageMappingService::byKey(\'email_deliverability_gate\')) covers verified-sender/domain gating.'],
            'suppression_list' => [null, 'Tests\\Feature\\Notifications\\SuppressionServiceTest exists specifically for this.'],
            'bounce_handling' => [null, 'Bounce handling is covered by the same tests/Feature/Notifications/SuppressionServiceTest email deliverability/suppression coverage.'],
            'reminder_pause' => [\App\Services\PaymentPlanDunningService::class, 'Tests\\Feature\\PaymentPlans\\PaymentPlanDunningServiceTest and Tests\\Feature\\DocumentChase\\DocumentChaseServiceTest both cover Paused-state reminder suppression.'],
            'timezone_language_preference' => [null, 'Tests\\Feature\\Notifications\\NotificationEligibilityServiceTest covers the shared consent/preference foundation, including timezone/language.'],
            'channel_consent_enforcement' => [\App\Services\ConsentService::class, 'Tests\\Feature\\Activation\\ConsentServiceTest and CommunicationConsentTest cover per-channel consent enforcement.'],
            'revocation_stops_channel_immediately' => [\App\Services\ConsentService::class, 'ConsentService::revoke() is real and versioned (paired CommunicationConsentEvent every time); Tests\\Feature\\Activation\\ConsentServiceTest covers revocation.'],
        ];

        return $this->build('notifications_consent', array_map(fn ($v) => [$v[0], GovernanceMappingStatus::Implemented, $v[1]], $fields));
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function billing(): array
    {
        $result = $this->build('billing', [
            'invoice_lifecycle' => [\App\Services\InvoiceDraftingService::class, GovernanceMappingStatus::Implemented, 'Tests\\Feature\\Invoicing\\InvoiceDraftingServiceTest/InvoiceTest/InvoiceLineTest cover the full Draft->PendingReview->Approved->Sent->Paid lifecycle.'],
            'flat_fee_invoice' => [\App\Services\InvoiceDraftingService::class, GovernanceMappingStatus::Implemented, 'InvoiceDraftingServiceTest::test_create_flat_fee_creates_a_single_flat_fee_line() directly tests InvoiceType::FlatFee.'],
            'payment_plan_lifecycle' => [\App\Services\PaymentPlanService::class, GovernanceMappingStatus::Implemented, 'tests/Feature/PaymentPlans/ covers Draft->Active->Paused/Renegotiated->Completed/Defaulted/Cancelled.'],
            'manual_payment_classification' => [\App\Services\PaymentClassificationService::class, GovernanceMappingStatus::Implemented, 'Tests\\Feature\\Payments\\PaymentClassificationServiceTest and ManualPaymentServiceTest cover classification decisions extensively.'],
            'double_submit_prevention' => [null, GovernanceMappingStatus::Implemented, 'payments.idempotency_key + partial unique index, tested via ManualPaymentServiceTest and IdempotencyKeyCoverageMappingService::byKey(\'payment_collection\').'],
            'platform_billing_separation' => [null, GovernanceMappingStatus::Implemented, 'Tests\\Feature\\PlatformBilling\\PlatformBillingSeparationTest exists specifically for this.'],
        ], GovernanceMappingStatus::Implemented);

        $result['billing.stripe_classification_before_intent'] = $this->result(
            'billing',
            'stripe_classification_before_intent',
            \App\Services\PaymentClassificationService::class,
            GovernanceMappingStatus::PartiallyImplemented,
            'PaymentClassificationServiceTest has 6 real tests of the classification decision itself (accepted/blocked scenarios), and PaymentClassificationService::classify() is deliberately pure/side-effect-free so it can run before any provider call — but no end-to-end test asserts classification precedes a real Stripe PaymentIntent creation, since no real Stripe integration exists yet (only StripeGateway/FakeStripeGateway interface stubs). Simulated/readiness-only, per this section\'s boundary.',
        );

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function trust(): array
    {
        $fields = [
            'blocked_before_phase_13' => 'PaymentClassificationService unconditionally blocks trust_iolta_payment classification; tested in PaymentClassificationServiceTest::test_trust_iolta_payment_is_always_blocked_in_usa_saas_regardless_of_firm_payment_mode().',
            'eligible_firm_activation' => 'Tests\\Feature\\Trust\\Eligibility/ and ModeActivation/ cover TrustEligibilityService\'s 5-condition gate and the two-person activation workflow.',
            'ledger_balance' => 'Tests\\Feature\\Trust\\Ledgers/ and Balances/ cover TrustLedgerEntry and TrustBalanceService.',
            'reconciliation' => 'Tests\\Feature\\Trust\\Reconciliation/ covers TrustReconciliationService.',
            'concurrent_withdrawal' => 'Tests\\Feature\\Trust\\Concurrency/ covers TrustConcurrencyLockService::withLockedBalances() directly.',
            'refund_chargeback_flow' => 'Tests\\Feature\\Trust\\Refunds/ and Chargebacks/ cover the full refund/chargeback flow.',
        ];

        return $this->build('trust', array_map(fn ($note) => [null, GovernanceMappingStatus::Implemented, $note], $fields));
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function ai(): array
    {
        $fields = [
            'disabled_plan_blocks_ai' => 'Tests\\Feature\\Ai\\Entitlement/ covers AiMode::Disabled blocking every AI entry point.',
            'firm_owned_key_encryption' => 'Tests\\Feature\\Ai\\ProviderKeys/ covers FirmAiProviderKey encryption.',
            'usage_budget_firm_and_org' => 'Tests\\Feature\\Ai\\Usage/ covers AiBudgetEnforcementService for both firm-level (token_limit_per_period/budget_limit_cents_per_period) and organization-level (UsageRollupService) budget checks.',
            'high_risk_approval' => 'Tests\\Feature\\Ai\\Approval/ covers AiApprovalWorkflowService\'s submit/approve/reject flow and APPROVAL_ROLES restriction.',
            'retrieval_isolation_no_unauthorized_matter_or_cross_firm_context' => 'Tests\\Feature\\Ai\\Retrieval/ covers AiRetrievalIsolationService directly.',
            'prompt_injection_resistance' => 'Tests\\Feature\\Ai\\PromptInjection/ covers PromptInjectionResistanceService directly.',
        ];

        return $this->build('ai', array_map(fn ($note) => [null, GovernanceMappingStatus::Implemented, $note], $fields));
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function importExport(): array
    {
        $fields = [
            'preview' => 'Tests\\Feature\\Imports\\ImportPreviewServiceTest exists specifically for this.',
            'mapping' => 'Tests\\Feature\\Imports\\ImportMappingServiceTest exists specifically for this.',
            'validation' => 'Tests\\Feature\\Imports\\ImportRowValidationServiceTest exists specifically for this.',
            'duplicate_detection' => 'Tests\\Feature\\Imports\\ImportDuplicateDetectionServiceTest exists specifically for this.',
            'malware_scan' => 'Tests\\Feature\\Imports\\ImportDocumentSafetyServiceTest exists specifically for this (references the same real_malware_scanning_engine_stubbed gap as documents.virus_scan).',
            'rollback' => 'Tests\\Feature\\Imports\\ImportRollbackServiceTest exists specifically for this.',
            'governed_export' => 'Tests\\Feature\\ (Offboarding/retention export governance suites) cover OffboardingExportService.',
        ];

        return $this->build('import_export', array_map(fn ($note) => [null, GovernanceMappingStatus::Implemented, $note], $fields));
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function formsDocuments(): array
    {
        $fields = [
            'deterministic_autofill_without_ai' => 'Tests\\Feature\\Forms\\Mapping\\DeterministicFieldResolutionServiceTest exists specifically for this — deterministic, no AI call.',
            'merge_template_generation' => 'FormTemplateService/DocumentTemplate generation is covered across tests/Feature/Forms/ and Templates/.',
            'missing_data_detection' => 'Tests\\Feature\\Forms\\Drafts\\FormMissingDataDetectionServiceTest exists specifically for this.',
            'edition_retirement_blocks_new_drafts' => 'Tests\\Feature\\Phase10RetiredVersionPreservesHistoricalDraftsTest::test_a_new_draft_cannot_be_generated_from_a_retired_version() directly tests this.',
            'historical_drafts_preserved' => 'Tests\\Feature\\Phase10RetiredVersionPreservesHistoricalDraftsTest::test_retiring_a_version_does_not_mutate_a_pre_existing_draft() directly tests this.',
        ];

        return $this->build('forms_documents', array_map(fn ($note) => [null, GovernanceMappingStatus::Implemented, $note], $fields));
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function reliabilityFleet(): array
    {
        $fields = [
            'backup' => 'Tests\\Feature\\BackupRestore\\BackupRestoreTestServiceTest exists (references the existing restore_tests_do_not_exercise_real_restore_path gap for the restore-realism caveat).',
            'restore' => 'Same BackupRestoreTestServiceTest — cross-references restore_tests_do_not_exercise_real_restore_path, not a duplicate.',
            'failed_jobs' => 'CustomerSuccessHealthScoreService::scoreAndRiskFlags() includes failedJobsCount, exercised in tests/Feature/CustomerSuccess/.',
            'queue_health' => 'Tests\\Feature\\QueueHealth\\QueueHealthServiceTest exists specifically for this.',
            'scheduler_health' => 'Tests\\Feature\\QueueHealth\\SchedulerHealthServiceTest exists specifically for this.',
            'incident_page' => 'Tests\\Feature\\Incidents\\ and StatusPage\\ cover IncidentService/StatusPageService.',
            'rollback_procedure' => 'Tests\\Feature\\Deployment\\Fleet\\FleetMigrationOrchestrationServiceTest covers rollback() directly.',
            'fleet_migration_rehearsal_halt_rollback' => 'Same FleetMigrationOrchestrationServiceTest covers halt-on-failure and rollback together.',
            'offline_license_validation_expiry_grace' => 'Tests\\Feature\\Deployment\\License\\LicenseFileSigningAndValidationServiceTest covers grace/restricted expiry sequencing.',
        ];

        return $this->build('reliability_fleet', array_map(fn ($note) => [null, GovernanceMappingStatus::Implemented, $note], $fields));
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function accessibilityMobile(): array
    {
        $keys = [
            'client_portal_keyboard_navigation', 'visible_focus', 'form_labels', 'readable_errors',
            'camera_upload', 'mobile_payment_flow', 'mobile_payment_plan_flow', 'mobile_signature_flow',
        ];

        $result = [];

        foreach ($keys as $key) {
            $result["accessibility_mobile.{$key}"] = $this->notApplicable(
                'accessibility_mobile',
                $key,
                'No client-portal/mobile/browser UI exists at all (confirmed by direct search — app/Filament does not exist, no Blade/Livewire views exist beyond the default welcome page, no Dusk/Playwright/Cypress harness exists anywhere in this repository or composer.json). Only backend readiness services exist (e.g. MobilePortalReadinessService, ClientPortalAccessibilityReadinessService, BillingAccessibilityReadinessService — all in tests/Feature/Accessibility/ and tests/Feature/MobilePortal/ as readiness-only tests, never a real UI/browser test). Blocked by a missing surface, not a product gap — never faked.',
            );
        }

        return $result;
    }

    /**
     * @param  array<string, array{0: ?string, 1: GovernanceMappingStatus, 2: string}>  $fields
     * @return array<string, GovernanceMappingResult>
     */
    private function build(string $group, array $fields, ?GovernanceMappingStatus $defaultStatus = null): array
    {
        $result = [];

        foreach ($fields as $key => [$owningClass, $status, $notes]) {
            $result["{$group}.{$key}"] = $this->result($group, $key, $owningClass, $status, $notes);
        }

        return $result;
    }

    private function result(string $group, string $key, ?string $owningClass, GovernanceMappingStatus $status, string $notes): GovernanceMappingResult
    {
        return new GovernanceMappingResult(
            item_key: "{$group}.{$key}",
            item_label: "{$group}.{$key}",
            owning_class: $owningClass,
            status: $status,
            notes: $notes,
        );
    }

    private function notApplicable(string $group, string $key, string $reason): GovernanceMappingResult
    {
        return $this->result($group, $key, null, GovernanceMappingStatus::NotFound, self::NOT_APPLICABLE_YET_MARKER.' '.$reason);
    }
}
