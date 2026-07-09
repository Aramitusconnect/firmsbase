<?php

namespace Tests\Feature\Signature\Certificates;

use App\Enums\SignatureEventType;
use App\Enums\SignatureRequestStatus;
use App\Models\Document;
use App\Models\DocumentHash;
use App\Models\Firm;
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
}
