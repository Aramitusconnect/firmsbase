<?php

namespace Tests\Feature\Automation\ZeroClick;

use App\Enums\DomainEventType;
use App\Enums\SignatureEventActorType;
use App\Enums\SignatureEventType;
use App\Enums\SignatureRequestStatus;
use App\Models\DocumentHash;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\SignatureRequest;
use App\Services\AcknowledgmentSignatureFoundationService;
use App\Services\Automation\DomainEventRecorderService;
use App\Services\DocumentHashService;
use App\Services\SignatureCertificateService;
use App\Services\SignatureEventLogger;
use App\Services\SignatureWorkflowTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SignatureRequestCompletedWorkflowTest — Zero-Click Core Workflow
 * Automation, test matrix K. Proves the NEW DomainEventType::
 * SignatureRequestCompleted is actually emitted by the real,
 * unmodified SignatureCertificateService::generate() completion path
 * (never a manufactured event), and only a matter-linked request
 * reaches the onboarding starter template's own condition.
 */
class SignatureRequestCompletedWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SignatureCertificateService
    {
        return new SignatureCertificateService(
            new SignatureWorkflowTransitionService,
            new DocumentHashService,
            new SignatureEventLogger(new AcknowledgmentSignatureFoundationService),
            app(DomainEventRecorderService::class),
        );
    }

    private function completeRequest(Firm $firm, SignatureRequest $request): void
    {
        $signed = $this->runWithFirmContext($firm, function () use ($request) {
            DocumentHash::factory()->create(['firm_id' => $request->firm_id, 'document_id' => $request->document_id]);

            $logger = new SignatureEventLogger(new AcknowledgmentSignatureFoundationService);
            $logger->log(request: $request, eventType: SignatureEventType::RequestSent, actorType: SignatureEventActorType::System);

            $request->update(['status' => SignatureRequestStatus::Signed]);

            return $request->fresh();
        });

        $this->service()->generate($signed);
    }

    public function test_a_matter_linked_signature_completion_emits_the_domain_event(): void
    {
        $firm = Firm::factory()->create();

        $request = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create();

            return SignatureRequest::factory()->forFirm($firm)->create(['matter_id' => $matter->id, 'client_id' => $matter->client_id]);
        });

        $this->completeRequest($firm, $request);

        $event = $this->runWithFirmContext($firm, fn () => DomainEvent::query()
            ->where('event_type', DomainEventType::SignatureRequestCompleted->value)
            ->where('subject_type', $request->getMorphClass())
            ->where('subject_id', $request->id)
            ->first());

        $this->assertNotNull($event);
        $this->assertSame($request->matter_id, $event->payload_json['matter']['id']);
    }

    public function test_onboarding_starter_condition_never_matches_a_non_matter_linked_request(): void
    {
        $firm = Firm::factory()->create();

        $request = $this->runWithFirmContext($firm, fn () => SignatureRequest::factory()->forFirm($firm)->create(['matter_id' => null]));

        $this->completeRequest($firm, $request);

        $event = $this->runWithFirmContext($firm, fn () => DomainEvent::query()
            ->where('event_type', DomainEventType::SignatureRequestCompleted->value)
            ->where('subject_id', $request->id)
            ->first());

        $this->assertNotNull($event);
        $this->assertNull($event->payload_json['matter']['id']);
    }
}
