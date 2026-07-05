<?php

namespace Tests\Feature\Signature\Certificates;

use App\Enums\SignatureRequestStatus;
use App\Models\Document;
use App\Models\DocumentHash;
use App\Models\SignatureRequest;
use App\Services\DocumentHashService;
use App\Services\SignatureCertificateService;
use App\Services\SignatureEventLogger;
use App\Services\SignatureWorkflowTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Required correctness test: implements the exact master-plan
 * transition rule "Completion requires evidence, hash, event trail,
 * and certificate-style record" as three explicit, checked
 * preconditions.
 */
class SignatureCertificateRequiresHashAndEventTrailTest extends TestCase
{
    use RefreshDatabase;

    private SignatureCertificateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SignatureCertificateService(
            new SignatureWorkflowTransitionService(),
            new DocumentHashService(),
            new SignatureEventLogger(new \App\Services\AcknowledgmentSignatureFoundationService()),
        );
    }

    public function test_generate_throws_when_request_is_not_signed(): void
    {
        $request = SignatureRequest::factory()->status(SignatureRequestStatus::Consented)->create();

        $this->expectException(\RuntimeException::class);
        $this->service->generate($request);
    }

    public function test_generate_throws_when_no_document_hash_exists(): void
    {
        $document = Document::factory()->create();
        $request = SignatureRequest::factory()->status(SignatureRequestStatus::Signed)->create(['document_id' => $document->id]);
        \App\Models\SignatureEvent::factory()->forRequest($request)->create();

        $this->expectException(\RuntimeException::class);
        $this->service->generate($request);
    }

    public function test_generate_throws_when_no_event_trail_exists(): void
    {
        $document = Document::factory()->create();
        $request = SignatureRequest::factory()->status(SignatureRequestStatus::Signed)->create(['document_id' => $document->id]);
        DocumentHash::factory()->forDocument($document)->create();

        $this->expectException(\RuntimeException::class);
        $this->service->generate($request);
    }

    public function test_generate_succeeds_once_all_three_preconditions_are_met(): void
    {
        $document = Document::factory()->create();
        $request = SignatureRequest::factory()->status(SignatureRequestStatus::Signed)->create(['document_id' => $document->id]);
        DocumentHash::factory()->forDocument($document)->create();
        \App\Models\SignatureEvent::factory()->forRequest($request)->create();

        $result = $this->service->generate($request);

        $this->assertNotNull($result->signatureCertificateId);
        $this->assertSame(SignatureRequestStatus::Completed, $request->fresh()->status);
    }
}
