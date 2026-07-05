<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirms no external e-sign provider SDK/API, no real SMS provider,
 * no real identity-verification provider, and no filing/submission
 * automation exists anywhere in the Phase 11 signature/PDF module.
 */
class Phase11NoRealProviderOrFilingTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_NEEDLES = [
        'DocuSign', 'docusign', 'Adobe Sign', 'adobesign', 'Dropbox Sign', 'HelloSign', 'hellosign',
        'GuzzleHttp', 'Http::', 'curl_exec', 'fsockopen',
        'Twilio', 'twilio', 'Nexmo', 'MessageBird',
        'Onfido', 'Jumio', 'IDology', 'Veriff',
        'efiling', 'e-filing', 'USCISApiClient', 'court_filing', 'auto_file',
    ];

    private const SIGNATURE_SERVICE_FILES = [
        'SignatureRequestWorkflowService.php',
        'SignatureRecipientWorkflowService.php',
        'SignatureRequestAggregationService.php',
        'DocumentHashService.php',
        'SignatureCertificateService.php',
        'SignatureEventLogger.php',
        'SignatureWorkflowTransitionService.php',
        'SignatureEsignLegalReadinessService.php',
        'SignatureAccessibilityReadinessService.php',
        'SignatureAndPdfAccessPolicyService.php',
        'PdfViewEventService.php',
        'PdfDownloadPolicyService.php',
        'PdfAnnotationService.php',
        'TenantSafeSignatureAndPdfPolicyService.php',
    ];

    public function test_no_signature_service_references_a_real_provider_sdk_network_call_or_filing_endpoint(): void
    {
        foreach (self::SIGNATURE_SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");
            $this->assertFileExists($path);

            $source = file_get_contents($path);

            foreach (self::FORBIDDEN_NEEDLES as $needle) {
                $this->assertStringNotContainsString($needle, $source, "{$filename} must not reference: {$needle}");
            }
        }
    }

    public function test_no_filament_blade_livewire_route_or_controller_files_exist_for_phase_11(): void
    {
        $this->assertFalse(is_dir(app_path('Filament/Resources/SignatureRequestResource')));
        $this->assertFalse(is_dir(app_path('Http/Controllers/Signature')));
        $this->assertFalse(is_dir(app_path('Http/Controllers/Pdf')));
        $this->assertFalse(is_dir(resource_path('views/signature')));
        $this->assertFalse(is_dir(app_path('Livewire/Signature')));
    }
}
