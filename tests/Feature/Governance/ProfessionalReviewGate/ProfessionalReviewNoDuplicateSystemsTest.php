<?php

namespace Tests\Feature\Governance\ProfessionalReviewGate;

use App\Enums\GovernanceMappingStatus;
use App\Services\ProfessionalReviewGateMappingService;
use Tests\TestCase;

/**
 * ProfessionalReviewNoDuplicateSystemsTest — gate 7
 * (systems.no_second_license_entitlement_signature_system): proves no
 * duplicate license/entitlement/signature system was introduced, that
 * organization-level licensing/signature classification matches the
 * AWS inspection (OrgLicenseService reuses LicenseStatus/LicenseEvent/
 * LicenseFileValidationService rather than a parallel system), that
 * EntitlementService remains the canonical entitlement resolver, and
 * that feature flags remain restrictive-only.
 */
class ProfessionalReviewNoDuplicateSystemsTest extends TestCase
{
    private ProfessionalReviewGateMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProfessionalReviewGateMappingService();
    }

    public function test_gate_seven_is_evaluated_and_passed(): void
    {
        $item = $this->service->byKey('systems.no_second_license_entitlement_signature_system');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
    }

    public function test_no_duplicate_org_level_license_or_signature_system_exists_in_the_repository(): void
    {
        $this->assertFileDoesNotExist(app_path('Services/OrgLicenseValidationService.php'));
        $this->assertFileDoesNotExist(app_path('Services/OrgSignatureService.php'));
        $this->assertFileDoesNotExist(app_path('Services/OrganizationCertificateService.php'));
        $this->assertFileDoesNotExist(app_path('Models/OrganizationCertificate.php'));
        $this->assertFileDoesNotExist(app_path('Models/OrgSignature.php'));
    }

    public function test_org_license_service_reuses_canonical_license_status_and_license_event(): void
    {
        $this->assertFileExists(app_path('Services/OrgLicenseService.php'));

        $source = file_get_contents(app_path('Services/OrgLicenseService.php'));

        $this->assertStringContainsString('LicenseStatus', $source);
        $this->assertStringContainsString('LicenseEvent', $source);
        $this->assertStringContainsString('OrgLicense', $source);
    }

    public function test_gate_seven_classification_notes_match_the_aws_inspection_conclusion(): void
    {
        $item = $this->service->byKey('systems.no_second_license_entitlement_signature_system');

        $this->assertStringContainsString('LicenseStatus', $item->notes);
        $this->assertStringContainsString('LicenseEvent', $item->notes);
        $this->assertStringContainsString('shared with FirmLicense', $item->notes);
    }

    public function test_license_file_validation_service_is_the_sole_canonical_validator(): void
    {
        $this->assertFileExists(app_path('Services/LicenseFileValidationService.php'));

        $duplicateValidators = glob(app_path('Services/*LicenseValidation*.php')) ?: [];
        $duplicateValidators = array_values(array_filter(
            $duplicateValidators,
            fn (string $path) => basename($path) !== 'LicenseFileValidationService.php',
        ));

        $this->assertEmpty($duplicateValidators, 'No parallel license-validation service may exist: '.implode(', ', $duplicateValidators));
    }

    public function test_entitlement_service_remains_the_sole_canonical_entitlement_resolver(): void
    {
        $this->assertFileExists(app_path('Services/EntitlementService.php'));

        $duplicateResolvers = glob(app_path('Services/*Entitlement*Resolver*.php')) ?: [];

        $this->assertEmpty($duplicateResolvers, 'No parallel entitlement resolver may exist: '.implode(', ', $duplicateResolvers));
    }

    public function test_feature_flags_remain_restrictive_only_per_gate_eight(): void
    {
        $item = $this->service->byKey('entitlements.no_feature_flag_grants_access');

        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertStringContainsString('FeatureGateService', $item->notes);
    }

    public function test_signature_certificate_service_has_no_organization_level_counterpart(): void
    {
        $this->assertFileExists(app_path('Services/SignatureCertificateService.php'));
        $this->assertFileDoesNotExist(app_path('Services/OrgSignatureCertificateService.php'));
    }
}
