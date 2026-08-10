<?php

namespace Tests\Feature\Signature\Certificates;

use App\Enums\SignatureRequestStatus;
use App\Models\Document;
use App\Models\DocumentHash;
use App\Models\Firm;
use App\Models\SignatureCertificate;
use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use App\Services\AcknowledgmentSignatureFoundationService;
use App\Services\Automation\DomainEventRecorderService;
use App\Services\DocumentHashService;
use App\Services\SignatureCertificateService;
use App\Services\SignatureEventLogger;
use App\Services\SignatureWorkflowTransitionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Required correctness test: the DB-unique signature_request_id
 * constraint makes a second certificate for the same request
 * structurally impossible, independent of the service's own pre-check.
 */
class SignatureCertificateOnePerRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_level_pre_check_blocks_a_second_generation_call(): void
    {
        $service = new SignatureCertificateService(
            new SignatureWorkflowTransitionService,
            new DocumentHashService,
            new SignatureEventLogger(new AcknowledgmentSignatureFoundationService),
            app(DomainEventRecorderService::class),
        );

        // documents has permanent FORCE ROW LEVEL SECURITY (Section
        // 39A-3C) — the document and its owning request must share
        // the same firm_id, or the request's own firm context won't
        // resolve the document at all.
        $firm = Firm::factory()->create();
        $document = Document::factory()->create(['firm_id' => $firm->id]);
        $request = SignatureRequest::factory()->status(SignatureRequestStatus::Signed)->create(['firm_id' => $firm->id, 'document_id' => $document->id]);
        DocumentHash::factory()->forDocument($document)->create();
        SignatureEvent::factory()->forRequest($request)->create();

        $service->generate($request);

        $this->expectException(\RuntimeException::class);
        $service->generate($request->fresh());
    }

    public function test_database_unique_constraint_rejects_a_second_row_for_the_same_request_bypassing_the_service(): void
    {
        $request = SignatureRequest::factory()->create();
        SignatureCertificate::factory()->create(['signature_request_id' => $request->id]);

        $this->expectException(QueryException::class);
        SignatureCertificate::factory()->create(['signature_request_id' => $request->id]);
    }
}
