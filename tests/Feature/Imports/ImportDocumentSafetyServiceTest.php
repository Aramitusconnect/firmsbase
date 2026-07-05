<?php

namespace Tests\Feature\Imports;

use App\Enums\DocumentScanStatus;
use App\Models\Firm;
use App\Models\ImportRow;
use App\Services\DocumentUploadPolicyService;
use App\Services\ImportDocumentSafetyService;
use App\Services\VirusScan\FakeVirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportDocumentSafetyServiceTest extends TestCase
{
    use RefreshDatabase;

    private ImportDocumentSafetyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImportDocumentSafetyService(new DocumentUploadPolicyService(), new FakeVirusScanner());
    }

    public function test_clean_document_passes_safety_checks(): void
    {
        $firm = Firm::factory()->create();
        $row = ImportRow::factory()->rawData([
            'original_filename' => 'contract.pdf',
            'size_bytes' => 1000,
            'storage_path' => 'imports/contract.pdf',
        ])->create();

        $status = $this->service->assertSafeToAccept($firm, $row);

        $this->assertSame(DocumentScanStatus::Clean, $status);
    }

    public function test_infected_document_is_rejected(): void
    {
        $firm = Firm::factory()->create();
        $row = ImportRow::factory()->rawData([
            'original_filename' => 'contract.pdf',
            'size_bytes' => 1000,
            'storage_path' => 'imports/infected-file.pdf',
        ])->create();

        $this->expectException(\RuntimeException::class);

        $this->service->assertSafeToAccept($firm, $row);
    }

    public function test_disallowed_extension_is_rejected(): void
    {
        $firm = Firm::factory()->create();
        $row = ImportRow::factory()->rawData([
            'original_filename' => 'malware.exe',
            'size_bytes' => 1000,
            'storage_path' => 'imports/malware.exe',
        ])->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->assertSafeToAccept($firm, $row);
    }

    public function test_document_belonging_to_a_different_firm_is_rejected(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $document = \App\Models\Document::factory()->create(['firm_id' => $firmB->id]);

        $this->expectException(\RuntimeException::class);

        $this->service->assertDocumentBelongsToFirm($document, $firmA);
    }
}
