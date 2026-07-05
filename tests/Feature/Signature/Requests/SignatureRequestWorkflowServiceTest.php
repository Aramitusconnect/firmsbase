<?php

namespace Tests\Feature\Signature\Requests;

use App\Enums\FirmUserRole;
use App\Enums\SignatureRequestStatus;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\EntitlementService;
use App\Services\SignatureAndPdfAccessPolicyService;
use App\Services\SignatureEventLogger;
use App\Services\SignatureRequestWorkflowService;
use App\Services\SignatureWorkflowTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignatureRequestWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    private SignatureRequestWorkflowService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SignatureRequestWorkflowService(
            new SignatureWorkflowTransitionService(),
            new SignatureEventLogger(new \App\Services\AcknowledgmentSignatureFoundationService()),
            new SignatureAndPdfAccessPolicyService(app(EntitlementService::class)),
        );
    }

    public function test_create_requires_exactly_one_source_document(): void
    {
        $firm = Firm::factory()->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);

        $this->expectException(\RuntimeException::class);
        $this->service->create($firm, 'Engagement Letter', $actor, null, null);
    }

    public function test_create_persists_a_draft_request_and_logs_request_created(): void
    {
        $firm = Firm::factory()->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $document = Document::factory()->create(['firm_id' => $firm->id]);

        $request = $this->service->create($firm, 'Engagement Letter', $actor, $document);

        $this->assertSame(SignatureRequestStatus::Draft, $request->status);
        $this->assertDatabaseHas('signature_events', [
            'signature_request_id' => $request->id,
            'event_type' => 'request_created',
        ]);
    }

    public function test_send_is_blocked_without_attorney_review(): void
    {
        $firm = Firm::factory()->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $document = Document::factory()->create(['firm_id' => $firm->id]);
        $request = $this->service->create($firm, 'Engagement Letter', $actor, $document);

        \App\Models\SignatureRequestRecipient::factory()->forRequest($request)->create();

        $this->expectException(\RuntimeException::class);
        $this->service->send($request, $actor);
    }

    public function test_send_succeeds_after_attorney_review_and_cascades_to_recipients(): void
    {
        $firm = Firm::factory()->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $document = Document::factory()->create(['firm_id' => $firm->id]);
        $request = $this->service->create($firm, 'Engagement Letter', $actor, $document);
        $recipient = \App\Models\SignatureRequestRecipient::factory()->forRequest($request)->create();

        $this->service->attorneyReview($request, $actor, 'Suitable for e-signature under UETA.');
        $request = $this->service->send($request->fresh(), $actor);

        $this->assertSame(SignatureRequestStatus::Sent, $request->status);
        $this->assertSame(SignatureRequestStatus::Sent, $recipient->fresh()->status);
    }

    public function test_void_cascades_to_non_terminal_recipients(): void
    {
        $firm = Firm::factory()->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $document = Document::factory()->create(['firm_id' => $firm->id]);
        $request = $this->service->create($firm, 'Engagement Letter', $actor, $document);
        $recipient = \App\Models\SignatureRequestRecipient::factory()->forRequest($request)->create();

        $voided = $this->service->void($request, $actor, 'Client withdrew.');

        $this->assertSame(SignatureRequestStatus::Voided, $voided->status);
        $this->assertSame(SignatureRequestStatus::Voided, $recipient->fresh()->status);
    }

    public function test_only_firm_owner_or_attorney_may_void(): void
    {
        $firm = Firm::factory()->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $document = Document::factory()->create(['firm_id' => $firm->id]);
        $request = $this->service->create($firm, 'Engagement Letter', $actor, $document);

        $paralegal = FirmUser::factory()->role(FirmUserRole::Paralegal)->create(['firm_id' => $firm->id]);

        $this->expectException(\RuntimeException::class);
        $this->service->void($request, $paralegal, 'Not permitted.');
    }
}
