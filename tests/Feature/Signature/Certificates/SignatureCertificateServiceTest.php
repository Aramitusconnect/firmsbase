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
use App\Services\Automation\DomainEventRecorderService;
use App\Services\DocumentHashService;
use App\Services\SignatureCertificateService;
use App\Services\SignatureEventLogger;
use App\Services\SignatureWorkflowTransitionService;
use App\Services\TenantContextResolver;
use App\Services\TenantContextService;
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
            new SignatureWorkflowTransitionService,
            new DocumentHashService,
            new SignatureEventLogger(new AcknowledgmentSignatureFoundationService),
            app(DomainEventRecorderService::class),
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

    /**
     * Section 39A-7 Wave 7 residual-concern proof (flagged by this
     * wave's own Phase 6 diff review): generate()'s
     * $request->events()->doesntExist() precondition check is a REAL DB
     * read. Before this wave's fix, calling generate() with no ambient
     * tenant context active anywhere would let that read run with no
     * app.current_firm_id session setting set, causing it to silently
     * (and incorrectly) evaluate "true" — RLS fails closed to 0 visible
     * rows — throwing the MISLEADING "no signature_events trail exists"
     * error even though an event genuinely exists.
     * SignatureCertificateService::generate() closes this by wrapping
     * that read (and everything through the trailing ->fresh()) in its
     * own runWithFirmContext($request->firm_id, ...) call (see that
     * class's own docblock). This test proves the fix actually works:
     * it explicitly clears BOTH the PHP-memory and PostgreSQL tenant
     * context immediately before calling generate(), then asserts
     * generate() still succeeds.
     */
    public function test_generate_correctly_detects_an_existing_events_trail_even_with_no_ambient_tenant_context(): void
    {
        $firm = Firm::factory()->create();
        $document = Document::factory()->create(['firm_id' => $firm->id]);
        $request = SignatureRequest::factory()->status(SignatureRequestStatus::Signed)->create(['firm_id' => $firm->id, 'document_id' => $document->id]);
        SignatureRequestRecipient::factory()->forRequest($request)->status(SignatureRequestStatus::Signed)->create();
        DocumentHash::factory()->forDocument($document)->create();
        SignatureEvent::factory()->forRequest($request)->create();

        (new TenantContextService)->clearDatabaseTenantContext();
        TenantContextResolver::clear();
        $this->assertNoDatabaseTenantContext();

        $this->service->generate($request);

        $certificate = $this->runWithFirmContext(
            $firm,
            fn () => SignatureCertificate::query()->where('signature_request_id', $request->id)->first(),
        );

        $this->assertNotNull(
            $certificate,
            'generate() must succeed under zero ambient context — its own internal wrap establishes context for the events()->doesntExist() read before checking it, so a genuinely-existing events trail must not be misreported as missing.'
        );
    }

    /**
     * Section 39A-7 Wave 7 residual-concern proof: generate()'s OTHER
     * flagged silent-failure risk — the $request->certificate()->exists()
     * duplicate-generation guard (line 56, structurally BEFORE the two
     * pre-existing DocumentHashService wraps) is also a real DB read.
     * Under FORCE RLS with no context, it would silently evaluate
     * "false" regardless of whether a certificate genuinely already
     * exists, letting a duplicate-generation attempt fall through to
     * SignatureCertificate::create() and surface a raw DB
     * unique-constraint violation instead of the clean, intended
     * RuntimeException. This test forces exactly that scenario (a
     * certificate already exists for a $request object whose in-memory
     * status still reads 'signed', mirroring a caller retrying after a
     * partial failure or a race) with zero ambient tenant context
     * active, and asserts the clean RuntimeException is thrown — not a
     * QueryException, and not a silently-created second certificate.
     */
    public function test_generate_correctly_detects_an_existing_certificate_even_with_no_ambient_tenant_context(): void
    {
        $firm = Firm::factory()->create();
        $document = Document::factory()->create(['firm_id' => $firm->id]);
        $request = SignatureRequest::factory()->status(SignatureRequestStatus::Signed)->create(['firm_id' => $firm->id, 'document_id' => $document->id]);
        $hash = DocumentHash::factory()->forDocument($document)->create();
        SignatureCertificate::factory()->forRequest($request, $hash)->create();

        (new TenantContextService)->clearDatabaseTenantContext();
        TenantContextResolver::clear();
        $this->assertNoDatabaseTenantContext();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A certificate has already been generated for this request.');

        $this->service->generate($request);
    }
}
