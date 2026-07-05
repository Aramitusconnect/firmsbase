<?php

namespace Tests\Feature\Signature\Requests;

use App\Enums\SignatureRequestStatus;
use App\Models\SignatureRequest;
use App\Models\SignatureRequestRecipient;
use App\Services\SignatureRequestAggregationService;
use App\Services\SignatureWorkflowTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asserts the reasoned aggregation rules documented on
 * SignatureRequestAggregationService: first-viewer for 'viewed',
 * unanimity for 'consented'/'signed', immediate cascade for
 * 'declined'/'expired', and that 'completed' is never set by this
 * service.
 */
class SignatureRequestAggregationServiceTest extends TestCase
{
    use RefreshDatabase;

    private SignatureRequestAggregationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SignatureRequestAggregationService(new SignatureWorkflowTransitionService());
    }

    private function requestWithRecipients(int $count): SignatureRequest
    {
        $request = SignatureRequest::factory()->status(SignatureRequestStatus::Sent)->create();
        SignatureRequestRecipient::factory()
            ->count($count)
            ->forRequest($request)
            ->status(SignatureRequestStatus::Sent)
            ->create();

        return $request->fresh();
    }

    public function test_request_advances_to_viewed_once_any_recipient_views(): void
    {
        $request = $this->requestWithRecipients(3);
        $recipients = $request->recipients;
        $recipients->first()->update(['status' => SignatureRequestStatus::Viewed]);

        $updated = $this->service->recompute($request);

        $this->assertSame(SignatureRequestStatus::Viewed, $updated->status);
    }

    public function test_request_does_not_advance_to_consented_until_all_recipients_consent(): void
    {
        $request = $this->requestWithRecipients(2);
        $recipients = $request->recipients;
        $recipients[0]->update(['status' => SignatureRequestStatus::Consented]);
        $recipients[1]->update(['status' => SignatureRequestStatus::Viewed]);

        $updated = $this->service->recompute($request);

        $this->assertSame(SignatureRequestStatus::Viewed, $updated->status);
    }

    public function test_request_advances_to_consented_once_all_recipients_consent(): void
    {
        $request = $this->requestWithRecipients(2);
        foreach ($request->recipients as $recipient) {
            $recipient->update(['status' => SignatureRequestStatus::Consented]);
        }

        $updated = $this->service->recompute($request);

        $this->assertSame(SignatureRequestStatus::Consented, $updated->status);
    }

    public function test_request_advances_to_signed_once_all_recipients_sign(): void
    {
        $request = $this->requestWithRecipients(2);
        foreach ($request->recipients as $recipient) {
            $recipient->update(['status' => SignatureRequestStatus::Signed]);
        }

        $updated = $this->service->recompute($request);

        $this->assertSame(SignatureRequestStatus::Signed, $updated->status);
    }

    public function test_any_recipient_decline_cascades_to_the_request_immediately(): void
    {
        $request = $this->requestWithRecipients(3);
        $recipients = $request->recipients;
        $recipients[0]->update(['status' => SignatureRequestStatus::Signed]);
        $recipients[1]->update(['status' => SignatureRequestStatus::Declined]);

        $updated = $this->service->recompute($request);

        $this->assertSame(SignatureRequestStatus::Declined, $updated->status);
    }

    public function test_a_voided_recipient_is_excluded_from_the_unanimity_check(): void
    {
        $request = $this->requestWithRecipients(2);
        $recipients = $request->recipients;
        $recipients[0]->update(['status' => SignatureRequestStatus::Signed]);
        $recipients[1]->update(['status' => SignatureRequestStatus::Voided]);

        $updated = $this->service->recompute($request);

        $this->assertSame(SignatureRequestStatus::Signed, $updated->status);
    }

    public function test_recompute_never_sets_completed(): void
    {
        $request = $this->requestWithRecipients(1);
        $request->recipients->first()->update(['status' => SignatureRequestStatus::Signed]);

        $updated = $this->service->recompute($request);

        $this->assertNotSame(SignatureRequestStatus::Completed, $updated->status);
        $this->assertSame(SignatureRequestStatus::Signed, $updated->status);
    }
}
