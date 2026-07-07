<?php

namespace Tests\Feature\Governance\AcceptanceTestMatrix;

use App\Services\AcceptanceTestMatrixMappingService;
use App\ValueObjects\GovernanceMappingResult;
use Tests\TestCase;

class AcceptanceTestMatrixMappingServiceTest extends TestCase
{
    private const REQUIRED_KEYS = [
        'tenant_isolation.cross_firm_query', 'tenant_isolation.rls_broken_scope',
        'tenant_isolation.cross_firm_document_access', 'tenant_isolation.cross_firm_api_key',
        'tenant_isolation.cross_firm_import_export',

        'security.two_factor_authentication', 'security.session_timeout', 'security.csrf',
        'security.rate_limit', 'security.password_policy', 'security.support_access_approval',
        'security.emergency_access_audit', 'security.per_firm_key_provisioning',

        'entitlements.ui_hidden', 'entitlements.route_blocked', 'entitlements.api_blocked',
        'entitlements.job_blocked', 'entitlements.command_blocked', 'entitlements.webhook_blocked',
        'entitlements.report_blocked', 'entitlements.import_export_blocked_for_disabled_module',
        'entitlements.org_inheritance_override_precedence', 'entitlements.flag_only_restricts_rule',

        'commercial_hierarchy.org_creation', 'commercial_hierarchy.firm_attach_detach',
        'commercial_hierarchy.consolidated_invoice_usage_attribution',
        'commercial_hierarchy.pooled_seat_enforcement_by_class',
        'commercial_hierarchy.commission_single_attribution_org_expansion',

        'practice_areas.firm_enables_multiple_areas', 'practice_areas.matter_has_one_primary_area',
        'practice_areas.template_pack_installed', 'practice_areas.template_version_pinned',
        'practice_areas.upgrade_preview_works',

        'conflicts.client_contact_party_company_email_phone_matching', 'conflicts.false_positive_review',
        'conflicts.matter_opening_gate_blocks_until_review', 'conflicts.firm_scoped_default',
        'conflicts.org_wide_opt_in_behavior',

        'documents.private_storage', 'documents.signed_urls_via_tenant_context', 'documents.virus_scan',
        'documents.rejected_file_type', 'documents.replacement_request', 'documents.document_audit_trail',

        'notifications_consent.verified_sender_domain_gate', 'notifications_consent.suppression_list',
        'notifications_consent.bounce_handling', 'notifications_consent.reminder_pause',
        'notifications_consent.timezone_language_preference', 'notifications_consent.channel_consent_enforcement',
        'notifications_consent.revocation_stops_channel_immediately',

        'billing.invoice_lifecycle', 'billing.flat_fee_invoice', 'billing.payment_plan_lifecycle',
        'billing.manual_payment_classification', 'billing.double_submit_prevention',
        'billing.stripe_classification_before_intent', 'billing.platform_billing_separation',

        'trust.blocked_before_phase_13', 'trust.eligible_firm_activation', 'trust.ledger_balance',
        'trust.reconciliation', 'trust.concurrent_withdrawal', 'trust.refund_chargeback_flow',

        'ai.disabled_plan_blocks_ai', 'ai.firm_owned_key_encryption', 'ai.usage_budget_firm_and_org',
        'ai.high_risk_approval', 'ai.retrieval_isolation_no_unauthorized_matter_or_cross_firm_context',
        'ai.prompt_injection_resistance',

        'import_export.preview', 'import_export.mapping', 'import_export.validation',
        'import_export.duplicate_detection', 'import_export.malware_scan', 'import_export.rollback',
        'import_export.governed_export',

        'forms_documents.deterministic_autofill_without_ai', 'forms_documents.merge_template_generation',
        'forms_documents.missing_data_detection', 'forms_documents.edition_retirement_blocks_new_drafts',
        'forms_documents.historical_drafts_preserved',

        'reliability_fleet.backup', 'reliability_fleet.restore', 'reliability_fleet.failed_jobs',
        'reliability_fleet.queue_health', 'reliability_fleet.scheduler_health', 'reliability_fleet.incident_page',
        'reliability_fleet.rollback_procedure', 'reliability_fleet.fleet_migration_rehearsal_halt_rollback',
        'reliability_fleet.offline_license_validation_expiry_grace',

        'accessibility_mobile.client_portal_keyboard_navigation', 'accessibility_mobile.visible_focus',
        'accessibility_mobile.form_labels', 'accessibility_mobile.readable_errors',
        'accessibility_mobile.camera_upload', 'accessibility_mobile.mobile_payment_flow',
        'accessibility_mobile.mobile_payment_plan_flow', 'accessibility_mobile.mobile_signature_flow',
    ];

    private const REQUIRED_GROUPS = [
        'tenant_isolation', 'security', 'entitlements', 'commercial_hierarchy', 'practice_areas',
        'conflicts', 'documents', 'notifications_consent', 'billing', 'trust', 'ai', 'import_export',
        'forms_documents', 'reliability_fleet', 'accessibility_mobile',
    ];

    private AcceptanceTestMatrixMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AcceptanceTestMatrixMappingService();
    }

    public function test_all_ninety_nine_acceptance_test_keys_are_explicitly_declared(): void
    {
        $declaredKeys = array_keys($this->service->all());

        $this->assertCount(99, $declaredKeys);

        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertContains($key, $declaredKeys, "Missing required acceptance-test key: {$key}");
        }
    }

    public function test_no_duplicate_keys_exist(): void
    {
        $keys = array_keys($this->service->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate acceptance-test key(s) found.');
    }

    public function test_all_fifteen_groups_are_represented(): void
    {
        $this->assertCount(15, $this->service->groups());

        foreach (self::REQUIRED_GROUPS as $group) {
            $this->assertContains($group, $this->service->groups());
            $this->assertNotEmpty($this->service->group($group), "Group {$group} should have at least one declared key.");
        }
    }

    public function test_all_entries_return_governance_mapping_result(): void
    {
        foreach ($this->service->all() as $key => $item) {
            $this->assertInstanceOf(GovernanceMappingResult::class, $item, "Entry {$key} must be a GovernanceMappingResult.");
        }
    }

    public function test_every_entry_has_notes_or_evidence(): void
    {
        foreach ($this->service->all() as $key => $item) {
            $this->assertNotEmpty($item->notes, "Item {$key} should have explanatory notes.");
        }
    }

    public function test_implemented_entries_identify_existing_test_or_service_evidence(): void
    {
        foreach ($this->service->implemented() as $key => $item) {
            $hasClassEvidence = $item->owning_class !== null;
            $hasTestEvidence = str_contains($item->notes, 'Test') || str_contains($item->notes, 'tests/');

            $this->assertTrue($hasClassEvidence || $hasTestEvidence, "Implemented item {$key} should identify existing test/service evidence.");
        }
    }

    public function test_known_gap_cross_references_are_present_where_applicable(): void
    {
        $rlsItem = $this->service->byKey('tenant_isolation.rls_broken_scope');
        $this->assertStringContainsString('rls_prepared_not_enforced', $rlsItem->notes);

        $signedUrlItem = $this->service->byKey('documents.signed_urls_via_tenant_context');
        $this->assertStringContainsString('signed_document_url_service_missing', $signedUrlItem->notes);

        $virusScanItem = $this->service->byKey('documents.virus_scan');
        $this->assertStringContainsString('real_malware_scanning_engine_stubbed', $virusScanItem->notes);

        $emergencyItem = $this->service->byKey('security.emergency_access_audit');
        $this->assertStringContainsString('emergency_support_access_high_risk_approval_not_wired', $emergencyItem->notes);
    }

    public function test_byKey_returns_null_for_an_unknown_key(): void
    {
        $this->assertNull($this->service->byKey('does_not_exist.key'));
    }
}
