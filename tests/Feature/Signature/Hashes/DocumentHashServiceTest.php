<?php

namespace Tests\Feature\Signature\Hashes;

use App\Enums\HashAlgorithm;
use App\Enums\SignatureSourceDocumentType;
use App\Models\Document;
use App\Models\Firm;
use App\Models\GeneratedDocument;
use App\Services\DocumentHashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentHashServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentHashService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DocumentHashService;
    }

    public function test_record_for_document_persists_the_caller_supplied_hash_value(): void
    {
        $firm = Firm::factory()->create();
        $document = Document::factory()->create(['firm_id' => $firm->id]);
        $callerSuppliedHash = hash('sha256', 'fixture-content');

        $result = $this->service->recordForDocument($document, $callerSuppliedHash);

        $this->assertSame($callerSuppliedHash, $result->hashValue);
        $this->assertSame(HashAlgorithm::Sha256, $result->algorithm);
        $this->assertDatabaseHas('document_hashes', [
            'document_id' => $document->id,
            'hash_value' => $callerSuppliedHash,
            'source_document_type' => SignatureSourceDocumentType::Document->value,
        ]);
    }

    public function test_latest_for_document_returns_the_most_recently_recorded_hash(): void
    {
        $firm = Firm::factory()->create();
        $document = Document::factory()->create(['firm_id' => $firm->id]);

        $this->travelTo(now()->subMinute());
        $this->service->recordForDocument($document, hash('sha256', 'v1'));

        $this->travelBack();
        $latestResult = $this->service->recordForDocument($document, hash('sha256', 'v2'));

        $latest = $this->service->latestForDocument($document);

        $this->assertSame($latestResult->documentHashId, $latest->id);
    }

    public function test_latest_for_document_returns_null_when_none_recorded(): void
    {
        $firm = Firm::factory()->create();
        $document = Document::factory()->create(['firm_id' => $firm->id]);

        $this->assertNull($this->service->latestForDocument($document));
    }

    /**
     * recordForGeneratedDocument() had no production caller before
     * DocumentGenerationService::generate() was wired to invoke it with
     * a real sha256 of the Dompdf-rendered PDF bytes it writes. This
     * still exercises the service directly (DocumentGenerationServiceTest
     * covers the real-caller path end to end), but recordForGeneratedDocument()
     * itself previously had zero coverage at all — only recordForDocument()
     * was tested here.
     */
    public function test_record_for_generated_document_persists_the_caller_supplied_hash_value(): void
    {
        $firm = Firm::factory()->create();
        $generatedDocument = GeneratedDocument::factory()->create(['firm_id' => $firm->id]);
        $callerSuppliedHash = hash('sha256', 'generated-fixture-content');

        $result = $this->service->recordForGeneratedDocument($generatedDocument, $callerSuppliedHash);

        $this->assertSame($callerSuppliedHash, $result->hashValue);
        $this->assertSame(HashAlgorithm::Sha256, $result->algorithm);
        $this->assertDatabaseHas('document_hashes', [
            'generated_document_id' => $generatedDocument->id,
            'hash_value' => $callerSuppliedHash,
            'source_document_type' => SignatureSourceDocumentType::GeneratedDocument->value,
        ]);
    }

    public function test_latest_for_generated_document_returns_the_most_recently_recorded_hash(): void
    {
        $firm = Firm::factory()->create();
        $generatedDocument = GeneratedDocument::factory()->create(['firm_id' => $firm->id]);

        $this->travelTo(now()->subMinute());
        $this->service->recordForGeneratedDocument($generatedDocument, hash('sha256', 'v1'));

        $this->travelBack();
        $latestResult = $this->service->recordForGeneratedDocument($generatedDocument, hash('sha256', 'v2'));

        $latest = $this->service->latestForGeneratedDocument($generatedDocument);

        $this->assertSame($latestResult->documentHashId, $latest->id);
    }

    public function test_latest_for_generated_document_returns_null_when_none_recorded(): void
    {
        $firm = Firm::factory()->create();
        $generatedDocument = GeneratedDocument::factory()->create(['firm_id' => $firm->id]);

        $this->assertNull($this->service->latestForGeneratedDocument($generatedDocument));
    }
}
