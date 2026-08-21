<?php

namespace Tests\Feature;

use App\Enums\FirmUserRole;
use App\Models\DocumentTemplateVersion;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\GeneratedDocument;
use App\Services\DeterministicFieldResolutionService;
use App\Services\DocumentGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Confirms no USCIS e-filing/mail submission or real external API call
 * exists anywhere in the Phase 10 module. Originally this also asserted
 * DocumentGenerationService never rendered a real PDF or wrote real
 * bytes to storage — the Non-Payment Completion Program (Workstream 2)
 * deliberately, correctly changed that: generate() now renders a real
 * PDF via Dompdf (the same library already proven in
 * FinancialEvidenceReportsPanel::exportPdf()) and writes it via
 * Storage::disk('local'), storing the real path on
 * storage_disk/storage_path. simulated_storage_path is kept for
 * backward compatibility but is no longer the operative artifact.
 * DocumentGenerationService is therefore excluded from the
 * rendering/storage needle check below (it still may never reference a
 * filing endpoint or an unrelated external API — that half of the
 * invariant is unchanged and still enforced for every file, including
 * this one). Every other Phase 10 service file is still held to the
 * original, unchanged "fake renderer only" rule — none of them were
 * touched by this program.
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

    /**
     * Non-Payment Completion Program, Workstream 2: DocumentGenerationService
     * now legitimately references Dompdf and Storage::disk/put — excused
     * from those two needles only, still checked against every other one
     * (filing endpoints, unrelated external APIs, other rendering libraries).
     * 'mpdf' is excused too only because it is a literal substring of
     * 'Dompdf' ("Dompdf" contains "mpdf") — this is a false-positive
     * needle collision, not a second rendering library; the real 'mpdf'
     * (standalone PhpMpdf-style usage) needle is still meaningful for
     * every other Phase 10 service file.
     */
    private const RENDERING_AND_STORAGE_EXCUSED_NEEDLES = ['Dompdf', 'mpdf', 'Storage::put', 'Storage::disk'];

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
            $excusedNeedles = $filename === 'DocumentGenerationService.php'
                ? self::RENDERING_AND_STORAGE_EXCUSED_NEEDLES
                : [];

            foreach (self::FORBIDDEN_NEEDLES as $needle) {
                if (in_array($needle, $excusedNeedles, true)) {
                    continue;
                }

                $this->assertStringNotContainsString($needle, $source, "{$filename} must not reference: {$needle}");
            }
        }
    }

    public function test_generated_document_is_now_backed_by_a_real_rendered_pdf(): void
    {
        Storage::fake('local');

        $firm = Firm::factory()->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $version = DocumentTemplateVersion::factory()->create(['merge_fields_schema' => []]);

        $service = new DocumentGenerationService(new DeterministicFieldResolutionService);
        $result = $service->generate($version, $actor, $firm->id);

        // simulated_storage_path is kept in its original format for
        // backward compatibility, but is no longer where the real bytes
        // live — storage_disk/storage_path is the real artifact now.
        $this->assertStringContainsString('.pdf', $result->simulatedStoragePath);
        // generated_documents carries FORCE ROW LEVEL SECURITY — the
        // read must run inside the same firm's tenant context.
        $generatedDocument = $this->runWithFirmContext(
            $firm,
            fn () => GeneratedDocument::query()->findOrFail($result->generatedDocumentId)
        );
        $this->assertNotNull($generatedDocument->storage_disk);
        $this->assertNotNull($generatedDocument->storage_path);
        Storage::disk($generatedDocument->storage_disk)->assertExists($generatedDocument->storage_path);
        $this->assertStringStartsWith('%PDF', Storage::disk($generatedDocument->storage_disk)->get($generatedDocument->storage_path));
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
