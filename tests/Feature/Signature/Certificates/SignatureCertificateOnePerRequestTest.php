<?php

namespace Tests\Feature\Signature\Certificates;

use App\Enums\SignatureRequestStatus;
use App\Models\Document;
use App\Models\DocumentHash;
use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use App\Services\AcknowledgmentSignatureFoundationService;
use App\Services\DocumentHashService;
use App\Services\SignatureCertificateService;
use App\Services\SignatureEventLogger;
use App\Services\SignatureWorkflowTransitionService;
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
            new SignatureWorkflowTransitionService(),
            new DocumentHashService(),
            new SignatureEventLogger(new AcknowledgmentSignatureFoundationService()),
        );

        $document = Document::factory()->create();
        $request = SignatureRequest::factory()->status(SignatureRequestStatus::Signed)->create(['document_id' => $document->id]);
        DocumentHash::factory()->forDocument($document)->create();
        SignatureEvent::factory()->forRequest($request)->create();

        $service->generate($request);

        $this->expectException(\RuntimeException::class);
        $service->generate($request->fresh());
    }

    public function test_database_unique_constraint_rejects_a_second_row_for_the_same_request_bypassing_the_service(): void
    {
        $request = SignatureRequest::factory()->create();
        \App\Models\SignatureCertificate::factory()->create(['signature_request_id' => $request->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        \App\Models\SignatureCertificate::factory()->create(['signature_request_id' => $request->id]);
    }
}
