<?php

namespace Tests\Feature\Governance\ProfessionalReviewGate;

use App\Services\ComplianceGapRegistryService;
use App\Services\ProfessionalReviewGateMappingService;
use Tests\TestCase;

/**
 * ProfessionalReviewGapRegistryTest — proves Section 37 added NO new
 * gap to ComplianceGapRegistryService: AWS inspection confirmed
 * AiRetrievalIsolationService performs hard, pre-retrieval firm/matter
 * scoping (never a shared index filtered only by metadata) and
 * OrgLicenseService reuses the canonical LicenseStatus/LicenseEvent/
 * LicenseFileValidationService mechanisms (not a parallel system) —
 * so neither ai_retrieval_hard_scope_not_enforced nor
 * duplicate_org_level_license_or_signature_system was warranted. The
 * gap count remains 21.
 */
class ProfessionalReviewGapRegistryTest extends TestCase
{
    private ComplianceGapRegistryService $registry;

    private ProfessionalReviewGateMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new ComplianceGapRegistryService();
        $this->service = new ProfessionalReviewGateMappingService();
    }

    public function test_starting_and_final_gap_count_remains_twenty_one(): void
    {
        $this->assertCount(21, $this->registry->all());
    }

    public function test_no_duplicate_gap_keys_exist(): void
    {
        $keys = array_map(fn ($item) => $item->key, $this->registry->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate gap key(s) found.');
    }

    public function test_neither_candidate_gap_was_added(): void
    {
        $this->assertFalse($this->registry->isTracked('ai_retrieval_hard_scope_not_enforced'));
        $this->assertFalse($this->registry->isTracked('duplicate_org_level_license_or_signature_system'));
    }

    public function test_professional_review_gate_mapping_service_declares_no_gaps(): void
    {
        $this->assertSame([], $this->service->gaps());
    }

    public function test_all_referenced_existing_gap_keys_exist_in_the_registry(): void
    {
        foreach ([
            'rls_prepared_not_enforced',
            'signed_document_url_service_missing',
            'real_malware_scanning_engine_stubbed',
            'emergency_support_access_high_risk_approval_not_wired',
            'ai_jobs_not_cancelled_when_entitlement_removed',
            'stripe_disconnect_payment_collection_block_not_enforced',
            'template_language_fallback_staff_notification_missing',
            'form_edition_watch_sla_controls_missing',
            'trust_ledger_entry_posting_actor_not_guaranteed',
            'org_admin_role_missing',
        ] as $key) {
            $this->assertTrue($this->registry->isTracked($key), "Expected existing gap '{$key}' to be tracked.");
        }
    }

    public function test_no_gap_was_added_solely_for_an_absent_ui_or_customer_facing_surface(): void
    {
        $forbiddenGapKeys = [
            'legal_specialist_customer_ui_missing',
            'accessibility_mobile_ui_missing',
            'browser_test_harness_missing',
            'admin_ui_missing_for_professional_review',
        ];

        foreach ($forbiddenGapKeys as $key) {
            $this->assertFalse($this->registry->isTracked($key), "Gap '{$key}' must not exist — absent UI/customer-facing surface alone is not a gap.");
        }
    }

    public function test_overall_gate_status_referenced_gaps_are_all_real_registry_entries(): void
    {
        $referenced = $this->service->overallGateStatus()['referenced_gaps'];

        $missing = array_values(array_filter(
            $referenced,
            fn (string $gapKey): bool => ! $this->registry->isTracked($gapKey)
        ));

        $this->assertSame(
            [],
            $missing,
            'Every referenced_gaps entry must exist in ComplianceGapRegistryService.'
        );
    }
}
