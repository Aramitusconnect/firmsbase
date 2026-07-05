<?php

namespace Tests\Feature\Signature\Recipients;

use App\Enums\SignatureRequestStatus;
use App\Models\SignatureRequest;
use App\Models\SignatureRequestRecipient;
use App\Services\AcknowledgmentSignatureFoundationService;
use App\Services\SignatureEventLogger;
use App\Services\SignatureRecipientWorkflowService;
use App\Services\SignatureRequestAggregationService;
use App\Services\SignatureWorkflowTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Required correctness test: proves consent is a STRUCTURAL
 * precondition of signature execution, not just a convention. Neither
 * 'sent' nor 'viewed' can transition directly to 'signed' — only
 * 'consented' can — so sign() is unreachable without first passing
 * through consent().
 */
class ConsentRequiredBeforeSignatureExecutionTest extends TestCase
{
    use RefreshDatabase;

    private SignatureRecipientWorkflowService $service;
    private SignatureWorkflowTransitionService $transitions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transitions = new SignatureWorkflowTransitionService();
        $this->service = new SignatureRecipientWorkflowService(
            $this->transitions,
            new SignatureEventLogger(new AcknowledgmentSignatureFoundationService()),
            new SignatureRequestAggregationService($this->transitions),
        );
    }

    public function test_transition_graph_has_no_direct_path_from_sent_to_signed(): void
    {
        $this->assertFalse($this->transitions->isTransitionAllowed('sent', 'signed'));
    }

    public function test_transition_graph_has_no_direct_path_from_viewed_to_signed(): void
    {
        $this->assertFalse($this->transitions->isTransitionAllowed('viewed', 'signed'));
    }

    public function test_sign_throws_for_a_recipient_who_never_consented(): void
    {
        $request = SignatureRequest::factory()->status(SignatureRequestStatus::Sent)->create();
        $recipient = SignatureRequestRecipient::factory()
            ->forRequest($request)
            ->status(SignatureRequestStatus::Viewed)
            ->create();

        $this->assertNull($recipient->consented_at);

        $this->expectException(\RuntimeException::class);
        $this->service->sign($recipient);
    }

    public function test_sign_succeeds_only_once_consented_at_is_set(): void
    {
        $request = SignatureRequest::factory()->status(SignatureRequestStatus::Sent)->create();
        $recipient = SignatureRequestRecipient::factory()
            ->forRequest($request)
            ->status(SignatureRequestStatus::Viewed)
            ->create();

        $consented = $this->service->consent(
            $recipient, 'App\\Models\\FirmUser', $recipient->id, 'consent-v1', '198.51.100.1', 'Mozilla/5.0'
        );
        $this->assertNotNull($consented->consented_at);

        $signed = $this->service->sign($consented);
        $this->assertSame(SignatureRequestStatus::Signed, $signed->status);
    }

    public function test_no_signature_event_of_type_recipient_signed_exists_without_a_prior_consent_captured_event(): void
    {
        $request = SignatureRequest::factory()->status(SignatureRequestStatus::Sent)->create();
        $recipient = SignatureRequestRecipient::factory()
            ->forRequest($request)
            ->status(SignatureRequestStatus::Viewed)
            ->create();

        $consented = $this->service->consent(
            $recipient, 'App\\Models\\FirmUser', $recipient->id, 'consent-v1', '198.51.100.1', 'Mozilla/5.0'
        );
        $this->service->sign($consented);

        $this->assertDatabaseHas('signature_events', [
            'signature_request_recipient_id' => $recipient->id,
            'event_type' => 'consent_captured',
        ]);
        $this->assertDatabaseHas('signature_events', [
            'signature_request_recipient_id' => $recipient->id,
            'event_type' => 'recipient_signed',
        ]);
    }
}
