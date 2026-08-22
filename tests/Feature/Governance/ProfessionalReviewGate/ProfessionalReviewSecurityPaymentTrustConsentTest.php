<?php

namespace Tests\Feature\Governance\ProfessionalReviewGate;

use App\Enums\GovernanceMappingStatus;
use App\Services\ConsentService;
use App\Services\PaymentClassificationService;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\ProfessionalReviewGateMappingService;
use Tests\TestCase;

/**
 * ProfessionalReviewSecurityPaymentTrustConsentTest — gates 3, 4, 5, 6,
 * 12: hidden navigation is never accepted as security evidence by
 * itself, payment classification/ledger bypass gate, trust/IOLTA
 * pre-foundation gate, SMS/WhatsApp consent gate, and platform
 * employee least-privilege gate.
 */
class ProfessionalReviewSecurityPaymentTrustConsentTest extends TestCase
{
    private ProfessionalReviewGateMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProfessionalReviewGateMappingService;
    }

    public function test_hidden_navigation_is_not_accepted_as_security_evidence_by_itself(): void
    {
        $item = $this->service->byKey('security.no_hidden_navigation_only_security');

        $this->assertNotNull($item);
        $this->assertStringNotContainsStringIgnoringCase('passed via hidden ui', $item->notes);
        $this->assertStringNotContainsStringIgnoringCase('passed because the ui is hidden', $item->notes);

        // The gate must cite a real backend enforcement class, not a
        // route/navigation absence, as its owning evidence.
        $this->assertSame(PlatformStaffAccessPolicyService::class, $item->owning_class);
    }

    public function test_payment_classification_and_ledger_gate_is_evaluated(): void
    {
        $item = $this->service->byKey('payments.no_payment_classification_or_ledger_bypass');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertSame(PaymentClassificationService::class, $item->owning_class);
        $this->assertFileExists(app_path('Services/PaymentClassificationService.php'));
    }

    public function test_trust_pre_foundation_gate_is_evaluated(): void
    {
        $item = $this->service->byKey('trust.no_trust_iolta_before_foundation_acceptance');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertStringContainsString('TrustEligibilityService', $item->notes);
        $this->assertFileExists(app_path('Services/TrustEligibilityService.php'));
        $this->assertFileExists(app_path('Services/TrustPilotExitCriteriaService.php'));
    }

    public function test_sms_whatsapp_consent_gate_is_evaluated(): void
    {
        $item = $this->service->byKey('communications.no_sms_whatsapp_without_unrevoked_consent');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertSame(ConsentService::class, $item->owning_class);
        $this->assertStringContainsString('revoke', $item->notes);
    }

    public function test_platform_employee_least_privilege_gate_is_evaluated(): void
    {
        $item = $this->service->byKey('platform_roles.no_unrestricted_employee_access_by_default');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertSame(PlatformStaffAccessPolicyService::class, $item->owning_class);
        $this->assertStringContainsString('HighRiskPlatformChangePolicyService', $item->notes);
        $this->assertFileExists(app_path('Services/PlatformStaffAccessPolicyService.php'));
    }

    public function test_security_entitlement_trust_ai_import_template_deployment_accessor_includes_the_expected_gates(): void
    {
        $keys = array_keys($this->service->securityEntitlementTrustAiImportTemplateDeployment());

        foreach ([
            'security.no_hidden_navigation_only_security',
            'entitlements.no_feature_flag_grants_access',
            'systems.no_second_license_entitlement_signature_system',
            'trust.no_trust_iolta_before_foundation_acceptance',
            'ai.no_cross_firm_or_metadata_only_retrieval',
            'imports.no_production_write_without_preview_validation_confirmation',
            'templates.no_silent_template_upgrade_or_historical_draft_mutation',
            'deployment.no_code_fork_or_connectivity_required_license_validation',
        ] as $expectedKey) {
            $this->assertContains($expectedKey, $keys);
        }
    }
}
