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

class SignatureRecipientWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    private SignatureRecipientWorkflowService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $transitions = new SignatureWorkflowTransitionService();
        $this->service = new SignatureRecipientWorkflowService(
            $transitions,
            new SignatureEventLogger(new AcknowledgmentSignatureFoundationService()),
            new SignatureRequestAggregationService($transitions),
        );
    }

    private function sentRecipient(): SignatureRequestRecipient
    {
        $request = SignatureRequest::factory()->status(SignatureRequestStatus::Sent)->create();

        return SignatureRequestRecipient::factory()
            ->forRequest($request)
            ->status(SignatureRequestStatus::Sent)
            ->create();
    }

    public function test_view_transitions_recipient_and_logs_event(): void
    {
        $recipient = $this->sentRecipient();

        $updated = $this->service->view($recipient, '203.0.113.5', 'Mozilla/5.0');

        $this->assertSame(SignatureRequestStatus::Viewed, $updated->status);
        $this->assertNotNull($updated->viewed_at);
        $this->assertDatabaseHas('signature_events', [
            'signature_request_recipient_id' => $recipient->id,
            'event_type' => 'recipient_viewed',
            'ip_address' => '203.0.113.5',
        ]);
    }

    public function test_consent_sets_cached_fields_and_logs_consent_captured_event(): void
    {
        $recipient = $this->sentRecipient();
        $recipient->update(['status' => SignatureRequestStatus::Viewed]);

        $updated = $this->service->consent(
            $recipient, 'App\\Models\\FirmUser', $recipient->id, 'consent-v1', '203.0.113.5', 'Mozilla/5.0'
        );

        $this->assertSame(SignatureRequestStatus::Consented, $updated->status);
        $this->assertSame('consent-v1', $updated->text_version);
        $this->assertNotNull($updated->consented_at);
    }

    public function test_sign_is_blocked_without_prior_consent(): void
    {
        $recipient = $this->sentRecipient();
        $recipient->update(['status' => SignatureRequestStatus::Viewed]);

        $this->expectException(\RuntimeException::class);
        $this->service->sign($recipient);
    }

    public function test_sign_succeeds_after_consent(): void
    {
        $recipient = $this->sentRecipient();
        $recipient->update(['status' => SignatureRequestStatus::Viewed]);
        $consented = $this->service->consent(
            $recipient, 'App\\Models\\FirmUser', $recipient->id, 'consent-v1', '203.0.113.5', 'Mozilla/5.0'
        );

        $signed = $this->service->sign($consented);

        $this->assertSame(SignatureRequestStatus::Signed, $signed->status);
        $this->assertNotNull($signed->signed_at);
    }

    public function test_decline_records_reason(): void
    {
        $recipient = $this->sentRecipient();

        $declined = $this->service->decline($recipient, 'Changed their mind.');

        $this->assertSame(SignatureRequestStatus::Declined, $declined->status);
        $this->assertSame('Changed their mind.', $declined->declined_reason);
    }
}
