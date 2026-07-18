<?php

namespace Tests\Feature\Signature\Certificates;

use App\Enums\SignatureEventType;
use App\Enums\SignatureRequestStatus;
use App\Enums\SignatureSourceDocumentType;
use App\Models\Document;
use App\Models\DocumentHash;
use App\Models\Firm;
use App\Models\GeneratedDocument;
use App\Models\SignatureCertificate;
use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use App\Models\SignatureRequestRecipient;
use App\Services\AcknowledgmentSignatureFoundationService;
use App\Services\DocumentHashService;
use App\Services\SignatureCertificateService;
use App\Services\SignatureEventLogger;
use App\Services\SignatureWorkflowTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignatureCertificateServiceTest extends TestCase
{
    use RefreshDatabase;

    private SignatureCertificateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SignatureCertificateService(
            new SignatureWorkflowTransitionService(),
            new DocumentHashService(),
            new SignatureEventLogger(new AcknowledgmentSignatureFoundationService()),
        );
    }

    public function test_certificate_data_json_includes_hash_value_and_recipient_evidence(): void
    {
        // documents has permanent FORCE ROW LEVEL SECURITY (Section
        // 39A-3C) — the document and its owning request must share
        // the same firm_id, or the request's own firm context won't
        // resolve the document at all.
        $firm = Firm::factory()->create();
        $document = Document::factory()->create(['firm_id' => $firm->id]);
        $request = SignatureRequest::factory()->status(SignatureRequestStatus::Signed)->create(['firm_id' => $firm->id, 'document_id' => $document->id]);
        $recipient = SignatureRequestRecipient::factory()
            ->forRequest($request)
            ->status(SignatureRequestStatus::Signed)
            ->create(['text_version' => 'consent-v1', 'consented_at' => now(), 'signed_at' => now()]);
        $hash = DocumentHash::factory()->forDocument($document)->create(['hash_value' => 'abc123']);
        SignatureEvent::factory()->forRequest($request)->create();

        $this->service->generate($request);

        $certificate = SignatureCertificate::query()->where('signature_request_id', $request->id)->firstOrFail();
        $data = $certificate->certificate_data_json;

        $this->assertSame('abc123', $data['document_hash_value']);
        $this->assertCount(1, $data['recipients']);
        $this->assertSame('consent-v1', $data['recipients'][0]['textVersion']);
        $this->assertSame($recipient->signer_email, $data['recipients'][0]['signerEmail']);
    }

    public function test_generate_logs_certificate_generated_and_request_completed_events(): void
    {
        $firm = Firm::factory()->create();
        $document = Document::factory()->create(['firm_id' => $firm->id]);
        $request = SignatureRequest::factory()->status(SignatureRequestStatus::Signed)->create(['firm_id' => $firm->id, 'document_id' => $document->id]);
        DocumentHash::factory()->forDocument($document)->create();
        SignatureEvent::factory()->forRequest($request)->create();

        $this->service->generate($request);

        $this->assertDatabaseHas('signature_events', [
            'signature_request_id' => $request->id,
            'event_type' => SignatureEventType::CertificateGenerated->value,
        ]);
        $this->assertDatabaseHas('signature_events', [
            'signature_request_id' => $request->id,
            'event_type' => SignatureEventType::RequestCompleted->value,
        ]);
    }

    /**
     * REQUIRED cross-wave fix regression test (Section 39A-6 Wave 6, per
     * this wave's own design §4.2): generate()'s GeneratedDocument source
     * branch was previously completely unwrapped in
     * runWithFirmContext(), relying on a comment that became false the
     * moment generated_documents itself was FORCE RLS'd in this same
     * wave. Before this fix, DocumentHashService::latestForGeneratedDocument()
     * would run with no app.current_firm_id session setting active and
     * silently return null (RLS fail-closed on document_hashes, which is
     * also forced in this wave), causing generate() to throw "no
     * document_hashes row exists" even though a real one existed. Per
     * the design doc's own note, ZERO existing test anywhere in this
     * codebase exercised this branch before this wave — this is the
     * first empirical proof it actually works.
     */
    public function test_generate_succeeds_for_a_signature_request_sourced_from_a_generated_document(): void
    {
        $firm = Firm::factory()->create();
        $generatedDocument = GeneratedDocument::factory()->forFirm($firm)->create();

        $request = SignatureRequest::factory()->status(SignatureRequestStatus::Signed)->create([
            'firm_id' => $firm->id,
            'source_document_type' => SignatureSourceDocumentType::GeneratedDocument->value,
            'document_id' => null,
            'generated_document_id' => $generatedDocument->id,
        ]);

        $recipient = SignatureRequestRecipient::factory()
            ->forRequest($request)
            ->status(SignatureRequestStatus::Signed)
            ->create(['text_version' => 'consent-v1', 'consented_at' => now(), 'signed_at' => now()]);

        $hash = DocumentHash::factory()->forGeneratedDocument($generatedDocument)->create(['hash_value' => 'generated-doc-hash-abc']);
        SignatureEvent::factory()->forRequest($request)->create();

        $this->service->generate($request);

        $certificate = SignatureCertificate::query()->where('signature_request_id', $request->id)->firstOrFail();
        $data = $certificate->certificate_data_json;

        $this->assertSame('generated-doc-hash-abc', $data['document_hash_value']);
        $this->assertSame($hash->id, $certificate->document_hash_id);
        $this->assertCount(1, $data['recipients']);
        $this->assertSame($recipient->signer_email, $data['recipients'][0]['signerEmail']);

        $completedRequest = SignatureRequest::query()->find($request->id);
        $this->assertSame(SignatureRequestStatus::Completed, $completedRequest->status);

        $this->assertDatabaseHas('signature_events', [
            'signature_request_id' => $request->id,
            'event_type' => SignatureEventType::CertificateGenerated->value,
        ]);
    }
}
