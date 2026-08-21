<?php

namespace Tests\Feature\Signature\Recipients;

use App\Enums\SignatureRequestStatus;
use App\Models\DocumentHash;
use App\Models\SignatureCertificate;
use App\Models\SignatureRequest;
use App\Models\SignatureRequestRecipient;
use App\Services\AcknowledgmentSignatureFoundationService;
use App\Services\Automation\DomainEventRecorderService;
use App\Services\DocumentHashService;
use App\Services\SignatureCertificateService;
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

        $transitions = new SignatureWorkflowTransitionService;
        $this->service = new SignatureRecipientWorkflowService(
            $transitions,
            new SignatureEventLogger(new AcknowledgmentSignatureFoundationService),
            new SignatureRequestAggregationService($transitions),
            new SignatureCertificateService(
                $transitions,
                new DocumentHashService,
                new SignatureEventLogger(new AcknowledgmentSignatureFoundationService),
                new DomainEventRecorderService,
            ),
        );
    }

    private function sentRecipient(): SignatureRequestRecipient
    {
        $request = SignatureRequest::factory()->status(SignatureRequestStatus::Sent)->create();

        // A document_hashes row is a real precondition of
        // SignatureCertificateService::generate() (see that class's
        // own docblock) -- present here so that the sole recipient of
        // this freshly-created request signing straight through to
        // request-level Signed (the only recipient => unanimous) can
        // reach certificate generation like it would in production,
        // rather than every sign()-based test having to set this up
        // individually.
        DocumentHash::factory()->forDocument($request->document)->create();

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

    /**
     * Non-payment completion program: sign() closes the
     * SignatureRequest -> Signed -> SignatureCertificateService::generate()
     * -> Completed gap. sentRecipient() creates a single-recipient
     * request, so signing that one recipient is, by itself, unanimous
     * -- the exact condition SignatureRequestAggregationService::recompute()
     * requires before advancing the request to Signed, which is in turn
     * what sign() gates certificate generation on.
     */
    public function test_signing_the_sole_recipient_generates_exactly_one_certificate_and_completes_the_request(): void
    {
        $recipient = $this->sentRecipient();
        $recipient->update(['status' => SignatureRequestStatus::Viewed]);
        $consented = $this->service->consent(
            $recipient, 'App\\Models\\FirmUser', $recipient->id, 'consent-v1', '203.0.113.5', 'Mozilla/5.0'
        );

        $signed = $this->service->sign($consented);

        // The signed artifact itself is retained regardless of what
        // certificate generation does afterward.
        $this->assertSame(SignatureRequestStatus::Signed, $signed->status);
        $this->assertNotNull($signed->signed_at);

        $this->assertSame(1, SignatureCertificate::query()->where('signature_request_id', $signed->signature_request_id)->count());

        $request = SignatureRequest::query()->find($signed->signature_request_id);
        $this->assertSame(SignatureRequestStatus::Completed, $request->status);
    }

    /**
     * A two-recipient request must NOT generate a certificate after
     * only the first recipient signs (the aggregation gate requires
     * unanimity) -- and once the second recipient's sign() call does
     * complete the unanimous condition, exactly one certificate must
     * exist, never two, even though both recipients individually
     * called sign().
     */
    public function test_signing_is_not_unanimous_until_every_recipient_has_signed(): void
    {
        $request = SignatureRequest::factory()->status(SignatureRequestStatus::Sent)->create();
        DocumentHash::factory()->forDocument($request->document)->create();

        $first = SignatureRequestRecipient::factory()->forRequest($request)->status(SignatureRequestStatus::Viewed)->create();
        $second = SignatureRequestRecipient::factory()->forRequest($request)->status(SignatureRequestStatus::Viewed)->create();

        $firstConsented = $this->service->consent($first, 'App\\Models\\FirmUser', $first->id, 'consent-v1', '203.0.113.5', 'Mozilla/5.0');
        $this->service->sign($firstConsented);

        $this->assertSame(0, SignatureCertificate::query()->where('signature_request_id', $request->id)->count());
        $this->assertNotSame(SignatureRequestStatus::Signed, SignatureRequest::query()->find($request->id)->status);

        $secondConsented = $this->service->consent($second, 'App\\Models\\FirmUser', $second->id, 'consent-v1', '203.0.113.5', 'Mozilla/5.0');
        $this->service->sign($secondConsented);

        $this->assertSame(1, SignatureCertificate::query()->where('signature_request_id', $request->id)->count());
        $this->assertSame(SignatureRequestStatus::Completed, SignatureRequest::query()->find($request->id)->status);
    }

    /**
     * Verifies no code path lets sign() reach certificate generation a
     * second time for the same recipient: SignatureWorkflowTransitionService's
     * graph blocks Signed -> Signed outright, so a repeated sign() call
     * throws before ever reaching the aggregation/certificate step --
     * proving the "repeated call" scenario cannot silently produce a
     * duplicate certificate through the public sign() entry point.
     */
    public function test_signing_an_already_signed_recipient_again_is_blocked_and_creates_no_second_certificate(): void
    {
        $recipient = $this->sentRecipient();
        $recipient->update(['status' => SignatureRequestStatus::Viewed]);
        $consented = $this->service->consent(
            $recipient, 'App\\Models\\FirmUser', $recipient->id, 'consent-v1', '203.0.113.5', 'Mozilla/5.0'
        );
        $signed = $this->service->sign($consented);

        $this->assertSame(1, SignatureCertificate::query()->where('signature_request_id', $signed->signature_request_id)->count());

        $this->expectException(\RuntimeException::class);

        try {
            $this->service->sign($signed);
        } finally {
            $this->assertSame(
                1,
                SignatureCertificate::query()->where('signature_request_id', $signed->signature_request_id)->count(),
                'A blocked repeated sign() call must never leave a second certificate behind.'
            );
        }
    }
}
