<?php

namespace Tests\Feature;

use App\Models\DocumentTemplateVersion;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Enums\FirmUserRole;
use App\Services\DeterministicFieldResolutionService;
use App\Services\DocumentGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirms no real PDF/DOCX rendering library, USCIS e-filing/mail
 * submission, or real external API call exists anywhere in the Phase
 * 10 module (project rule: fake/simulated renderer only —
 * simulated_storage_path is a descriptive string nothing ever writes
 * to).
 */
class Phase10NoRealRenderingOrFilingTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_NEEDLES = [
        'TCPDF', 'FPDF', 'Dompdf', 'mpdf', 'PhpWord', 'PhpSpreadsheet',
        'ZipArchive', 'GuzzleHttp', 'Http::', 'curl_exec',
        'uscis.gov', 'e-filing', 'efiling', 'USCISApiClient',
        'Storage::put', 'Storage::disk', 'file_put_contents',
        'fopen(', 'imagecreate',
    ];

    private const SERVICE_FILES = [
        'FormTemplateService.php', 'FormFieldService.php', 'FormMappingRuleService.php',
        'DeterministicFieldResolutionService.php', 'FormDraftGenerationService.php',
        'FormMissingDataDetectionService.php', 'FormReviewChecklistService.php',
        'FormReviewService.php', 'FormEditionWatchService.php', 'FormAccessibilityReadinessService.php',
        'DocumentTemplateService.php', 'DocumentGenerationService.php', 'DocumentReviewService.php',
        'ReviewWorkflowTransitionService.php', 'FormAndDocumentAccessPolicyService.php',
        'TenantSafeFormAndDocumentPolicyService.php',
    ];

    public function test_no_service_references_a_real_rendering_library_or_filing_endpoint(): void
    {
        foreach (self::SERVICE_FILES as $filename) {
            $source = file_get_contents(app_path("Services/{$filename}"));

            foreach (self::FORBIDDEN_NEEDLES as $needle) {
                $this->assertStringNotContainsString($needle, $source, "{$filename} must not reference: {$needle}");
            }
        }
    }

    public function test_generated_document_simulated_storage_path_is_never_backed_by_a_real_file(): void
    {
        $firm = Firm::factory()->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $version = DocumentTemplateVersion::factory()->create(['merge_fields_schema' => []]);

        $service = new DocumentGenerationService(new DeterministicFieldResolutionService());
        $result = $service->generate($version, $actor, $firm->id);

        $this->assertStringContainsString('.pdf', $result->simulatedStoragePath);
        $this->assertFileDoesNotExist(storage_path('app/'.$result->simulatedStoragePath));
    }

    public function test_no_filament_blade_livewire_or_route_files_exist_for_phase_10(): void
    {
        $this->assertFalse(is_dir(app_path('Filament/Resources/FormTemplateResource')));
        $this->assertFalse(is_dir(app_path('Filament/Resources/DocumentTemplateResource')));
        $this->assertFalse(is_dir(app_path('Http/Controllers/Forms')));
        $this->assertFalse(is_dir(app_path('Http/Controllers/DocumentGeneration')));
        $this->assertFalse(is_dir(resource_path('views/forms')));
        $this->assertFalse(is_dir(app_path('Livewire/Forms')));
    }
}
