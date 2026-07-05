<?php

namespace Tests\Feature\Signature\Hashes;

use App\Enums\HashAlgorithm;
use App\Enums\SignatureSourceDocumentType;
use App\Models\Document;
use App\Models\Firm;
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
        $this->service = new DocumentHashService();
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
}
