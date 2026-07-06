<?php

namespace Tests\Feature\Webhooks\Wiring;

use App\Enums\SignatureRequestStatus;
use App\Enums\WebhookEventType;
use App\Models\Document;
use App\Models\DocumentHash;
use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use App\Services\AcknowledgmentSignatureFoundationService;
use App\Services\DocumentHashService;
use App\Services\SignatureCertificateService;
use App\Services\SignatureEventLogger;
use App\Services\SignatureWorkflowTransitionService;
use App\Services\WebhookEventRecorderService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Feature\Webhooks\Concerns\SetsUpWebhookEntitledFirm;
use Tests\TestCase;

/**
 * signature.completed is wired at the single real owner (Phase 14b
 * decision J): SignatureCertificateService::generate() — NOT
 * SignatureRequestAggregationService, whose own docblock states
 * "'completed' is set ONLY by SignatureCertificateService." The
 * signature_certificates.signature_request_id DB-unique constraint
 * makes a duplicate completion (and duplicate webhook event)
 * structurally impossible.
 */
class SignatureCompletedWiringTest extends TestCase
{
    use DatabaseMigrations, SetsUpWebhookEntitledFirm;

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

    private function signedRequestReadyForCertificate(\App\Models\Firm $firm): SignatureRequest
    {
        $document = Document::factory()->create(['firm_id' => $firm->id]);
        $request = SignatureRequest::factory()->status(SignatureRequestStatus::Signed)->create([
            'firm_id' => $firm->id,
            'document_id' => $document->id,
        ]);
        DocumentHash::factory()->forDocument($document)->create();
        SignatureEvent::factory()->forRequest($request)->create();

        return $request;
    }

    public function test_signature_completed_fires_exactly_once_on_successful_certificate_generation(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $request = $this->signedRequestReadyForCertificate($firm);

        $this->service->generate($request);

        $this->assertSame(SignatureRequestStatus::Completed, $request->fresh()->status);
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', [
            'event_type' => WebhookEventType::SignatureCompleted->value,
            'subject_type' => SignatureRequest::class,
            'subject_id' => $request->id,
        ]);
    }

    public function test_signature_completed_does_not_fire_when_request_is_not_yet_signed(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $document = Document::factory()->create(['firm_id' => $firm->id]);
        $request = SignatureRequest::factory()->status(SignatureRequestStatus::Sent)->create([
            'firm_id' => $firm->id,
            'document_id' => $document->id,
        ]);

        try {
            $this->service->generate($request);
            $this->fail('Expected a RuntimeException for a request that is not yet signed.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_a_second_certificate_generation_attempt_does_not_fire_a_second_webhook_event(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $request = $this->signedRequestReadyForCertificate($firm);

        $this->service->generate($request);

        try {
            $this->service->generate($request->fresh());
            $this->fail('Expected a RuntimeException — a certificate already exists for this request.');
        } catch (\RuntimeException) {
            // expected — DB-unique constraint on signature_certificates.signature_request_id backs this
        }

        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_recorder_exception_does_not_break_certificate_generation(): void
    {
        $this->mock(WebhookEventRecorderService::class, function ($mock) {
            $mock->shouldReceive('record')->andThrow(new \RuntimeException('simulated recorder failure'));
        });

        $firm = $this->makeWebhookEntitledFirm();
        $request = $this->signedRequestReadyForCertificate($firm);

        $this->service->generate($request);

        $this->assertSame(SignatureRequestStatus::Completed, $request->fresh()->status);
        $this->assertDatabaseHas('signature_certificates', ['signature_request_id' => $request->id]);
    }
}
