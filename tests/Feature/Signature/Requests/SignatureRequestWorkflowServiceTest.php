<?php

namespace Tests\Feature\Signature\Requests;

use App\Enums\FirmUserRole;
use App\Enums\SignatureRequestStatus;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\SignatureRequest;
use App\Models\SignatureRequestRecipient;
use App\Services\AcknowledgmentSignatureFoundationService;
use App\Services\EntitlementService;
use App\Services\SignatureAndPdfAccessPolicyService;
use App\Services\SignatureEventLogger;
use App\Services\SignatureRequestWorkflowService;
use App\Services\SignatureWorkflowTransitionService;
use App\Services\TenantContextResolver;
use App\Services\TenantContextService;
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
            new SignatureWorkflowTransitionService,
            new SignatureEventLogger(new AcknowledgmentSignatureFoundationService),
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

        SignatureRequestRecipient::factory()->forRequest($request)->create();

        $this->expectException(\RuntimeException::class);
        $this->service->send($request, $actor);
    }

    public function test_send_succeeds_after_attorney_review_and_cascades_to_recipients(): void
    {
        $firm = Firm::factory()->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $document = Document::factory()->create(['firm_id' => $firm->id]);
        $request = $this->service->create($firm, 'Engagement Letter', $actor, $document);
        $recipient = SignatureRequestRecipient::factory()->forRequest($request)->create();

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
        $recipient = SignatureRequestRecipient::factory()->forRequest($request)->create();

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

    /**
     * Section 39A-7 Wave 7 residual-concern proof (flagged by this
     * wave's own Phase 6 diff review): send()'s
     * $request->recipients()->doesntExist() precondition check is a
     * REAL DB read. Before this wave's fix, calling send() with no
     * ambient tenant context active anywhere would let that read run
     * with no app.current_firm_id session setting set, causing it to
     * silently (and incorrectly) evaluate "true" — RLS fails closed to
     * 0 visible rows — throwing the MISLEADING "Cannot send: this
     * request has no recipients" business-logic error even though a
     * recipient genuinely exists. SignatureRequestWorkflowService::send()
     * closes this by wrapping that specific read in its own
     * runWithFirmContext($request->firm_id, ...) call (see that class's
     * own docblock). This test proves the fix actually works: it
     * explicitly clears BOTH the PHP-memory and PostgreSQL tenant
     * context immediately before calling send(), then asserts send()
     * still succeeds — i.e. the caller does not need its own ambient
     * wrap around the call site for this precondition read to see the
     * true state of the data.
     */
    public function test_send_correctly_detects_existing_recipients_even_with_no_ambient_tenant_context(): void
    {
        $firm = Firm::factory()->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $document = Document::factory()->create(['firm_id' => $firm->id]);
        $request = $this->service->create($firm, 'Engagement Letter', $actor, $document);
        SignatureRequestRecipient::factory()->forRequest($request)->create();
        $this->service->attorneyReview($request, $actor, 'Suitable for e-signature under UETA.');

        $request = $this->runWithFirmContext($firm, fn () => $request->fresh());

        (new TenantContextService)->clearDatabaseTenantContext();
        TenantContextResolver::clear();
        $this->assertNoDatabaseTenantContext();

        $sent = $this->service->send($request, $actor);

        $this->assertSame(
            SignatureRequestStatus::Sent,
            $sent->status,
            'send() must correctly detect the genuinely-existing recipient even though the test established zero ambient context before calling it — proving the internal wrap, not a misleading "no recipients" error.'
        );
    }

    /**
     * Section 39A-7 Wave 7 residual-concern proof: empirically confirms
     * send()'s per-recipient loop is NOT wrapped in one shared,
     * all-or-nothing transaction — this wave's design (§4.1) explicitly
     * chose independent per-statement wraps specifically so FORCE RLS
     * activation would not silently introduce a NEW atomicity guarantee
     * this class has never claimed. A temporary, test-only Eloquent
     * `updating` listener (removed in the `finally` block below; safe
     * because SignatureRequestRecipient itself registers no booted()
     * event listeners of its own — confirmed by direct inspection of
     * app/Models/SignatureRequestRecipient.php) forces a synchronous
     * exception on the 2nd of 3 recipients' update() calls, simulating
     * a mid-loop failure. Today's failure semantics must match: the 1st
     * recipient's update already committed, the 2nd/3rd remain
     * untouched, and the request's own status update (a separate,
     * earlier wrap, before the loop even starts) remains committed —
     * exactly the same partial-application behavior this method already
     * had before FORCE RLS activation (it was never wrapped in a shared
     * DB::transaction() across the whole loop, before or after this
     * wave).
     */
    public function test_send_recipient_loop_is_not_atomic_matching_documented_pre_rls_behavior(): void
    {
        $firm = Firm::factory()->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $document = Document::factory()->create(['firm_id' => $firm->id]);
        $request = $this->service->create($firm, 'Engagement Letter', $actor, $document);
        SignatureRequestRecipient::factory()->count(3)->forRequest($request)->create();
        $this->service->attorneyReview($request, $actor, 'Suitable for e-signature under UETA.');

        $request = $this->runWithFirmContext($firm, fn () => $request->fresh());

        $callCount = 0;
        SignatureRequestRecipient::updating(function () use (&$callCount) {
            $callCount++;

            if ($callCount === 2) {
                throw new \RuntimeException('simulated failure on the 2nd recipient update');
            }
        });

        try {
            try {
                $this->service->send($request, $actor);
                $this->fail('Expected the simulated exception to propagate out of send().');
            } catch (\RuntimeException $e) {
                $this->assertSame('simulated failure on the 2nd recipient update', $e->getMessage());
            }
        } finally {
            SignatureRequestRecipient::flushEventListeners();
        }

        $sentRecipientCount = $this->runWithFirmContext(
            $firm,
            fn () => SignatureRequestRecipient::where('signature_request_id', $request->id)
                ->where('status', SignatureRequestStatus::Sent->value)
                ->count(),
        );

        $this->assertSame(
            1,
            $sentRecipientCount,
            'Exactly one recipient update must have committed before the simulated failure on the 2nd update — proving the loop is NOT wrapped in one shared, all-or-nothing transaction.'
        );

        $requestStatus = $this->runWithFirmContext($firm, fn () => SignatureRequest::find($request->id)->status);

        $this->assertSame(
            SignatureRequestStatus::Sent,
            $requestStatus,
            "The request's own status update (a separate, earlier wrap) must remain committed even though the later per-recipient loop failed partway through — the same non-atomic behavior this method had before FORCE RLS activation."
        );
    }
}
