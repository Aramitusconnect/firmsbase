<?php

namespace Tests\Feature\Governance\CrossCutting;

use App\Services\ComplianceReviewGateMappingService;
use Tests\TestCase;

class ComplianceReviewGateMappingServiceTest extends TestCase
{
    private const REQUIRED_ITEM_KEYS = [
        'trust_iolta_jurisdiction_review',
        'esign_ueta_evidence_review',
        'communication_consent_email_sms_whatsapp_portal',
        'tcpa_automated_sms_exposure',
        'consent_records_captured_versioned_enforced',
        'imputed_conflict_firm_default_org_opt_in',
        'vendor_subprocessor_register_disclosures',
        'ai_provider_zero_retention_terms',
        'retention_legal_hold_export_deletion_key_destruction_offboarding',
        'ai_disclaimers_human_review_firm_keys_data_usage_logging_provider_terms',
    ];

    private ComplianceReviewGateMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ComplianceReviewGateMappingService();
    }

    public function test_all_ten_compliance_items_are_declared(): void
    {
        $items = $this->service->all();

        $this->assertCount(10, $items);

        $declaredKeys = array_map(fn ($item) => $item->item_key, $items);

        foreach (self::REQUIRED_ITEM_KEYS as $key) {
            $this->assertContains($key, $declaredKeys, "Missing required compliance item: {$key}");
        }
    }

    public function test_every_item_has_an_owning_class_or_clear_notes(): void
    {
        foreach ($this->service->all() as $item) {
            $this->assertTrue(
                $item->owning_class !== null || ! empty($item->notes),
                "Item {$item->item_key} has neither an owning_class nor notes.",
            );
        }
    }

    public function test_byKey_returns_null_for_an_unknown_key(): void
    {
        $this->assertNull($this->service->byKey('does_not_exist'));
    }

    public function test_trust_jurisdiction_maps_to_trust_jurisdiction_readiness_service(): void
    {
        $this->assertSame(
            \App\Services\TrustJurisdictionReadinessService::class,
            $this->service->byKey('trust_iolta_jurisdiction_review')->owning_class,
        );
    }

    public function test_esign_ueta_maps_to_signature_esign_legal_readiness_service(): void
    {
        $item = $this->service->byKey('esign_ueta_evidence_review');

        $this->assertSame(\App\Services\SignatureEsignLegalReadinessService::class, $item->owning_class);
        $this->assertStringContainsString('SignatureCertificateService', $item->notes);
    }

    public function test_consent_and_tcpa_map_to_consent_service_and_communication_consent_models(): void
    {
        $consentItem = $this->service->byKey('consent_records_captured_versioned_enforced');
        $this->assertSame(\App\Services\ConsentService::class, $consentItem->owning_class);

        $channelsItem = $this->service->byKey('communication_consent_email_sms_whatsapp_portal');
        $this->assertSame(\App\Services\ConsentService::class, $channelsItem->owning_class);

        $tcpaItem = $this->service->byKey('tcpa_automated_sms_exposure');
        $this->assertSame(\App\Services\ConsentService::class, $tcpaItem->owning_class);
    }

    public function test_imputed_conflict_maps_to_conflict_check_service_and_conflict_scope(): void
    {
        $item = $this->service->byKey('imputed_conflict_firm_default_org_opt_in');

        $this->assertSame(\App\Services\ConflictCheckService::class, $item->owning_class);
        $this->assertStringContainsString('ConflictScope', $item->notes);
    }

    public function test_vendor_subprocessor_maps_to_vendor_register_mechanisms(): void
    {
        $item = $this->service->byKey('vendor_subprocessor_register_disclosures');

        $this->assertSame(\App\Services\VendorRegisterService::class, $item->owning_class);
    }

    public function test_phase_17_retention_offboarding_key_destruction_maps_to_phase_17_services(): void
    {
        $item = $this->service->byKey('retention_legal_hold_export_deletion_key_destruction_offboarding');

        $this->assertSame(\App\Services\OffboardingRequestService::class, $item->owning_class);
        $this->assertStringContainsString('RetentionPolicyService', $item->notes);
        $this->assertStringContainsString('LegalHoldService', $item->notes);
        $this->assertStringContainsString('KeyDestructionApprovalService', $item->notes);
    }

    public function test_ai_legal_governance_maps_to_phase_15_ai_governance_services(): void
    {
        $item = $this->service->byKey('ai_disclaimers_human_review_firm_keys_data_usage_logging_provider_terms');

        $this->assertSame(\App\Services\AiApprovalWorkflowService::class, $item->owning_class);
        $this->assertStringContainsString('FirmAiProviderKey', $item->notes);
        $this->assertStringContainsString('AiUsageEvent', $item->notes);
    }

    public function test_gaps_never_includes_an_implemented_item(): void
    {
        foreach ($this->service->gaps() as $item) {
            $this->assertNotSame(\App\Enums\GovernanceMappingStatus::Implemented, $item->status);
        }
    }
}
