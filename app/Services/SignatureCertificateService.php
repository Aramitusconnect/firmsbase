<?php

namespace App\Services;

use App\Enums\SignatureEventActorType;
use App\Enums\SignatureEventType;
use App\Enums\SignatureRequestStatus;
use App\Enums\SignatureSourceDocumentType;
use App\Enums\WebhookEventType;
use App\Models\DocumentHash;
use App\Models\SignatureCertificate;
use App\Models\SignatureRequest;
use App\ValueObjects\CertificateGenerationResult;
use App\ValueObjects\SignatureEvidenceSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * SignatureCertificateService — the ONLY place a signature_certificates
 * row is created. Implements the exact master-plan transition rule:
 * "Completion requires evidence, hash, event trail, and
 * certificate-style record" — generate() asserts all three
 * preconditions (request already Signed, a document_hashes row exists,
 * at least one signature_events row exists) before writing anything,
 * then flips the request to Completed as its final step. The
 * signature_request_id DB-unique constraint (see migration) makes a
 * second certificate for the same request structurally impossible,
 * independent of this service's own pre-check.
 *
 * Phase 14b addition: generate() fires signature.completed exactly
 * once, after the request's status is actually set to Completed. The
 * DB-unique constraint on signature_certificates.signature_request_id
 * means a second call to generate() for the same request throws at the
 * "already generated" pre-check above, long before this point — so a
 * duplicate completion (and a duplicate webhook event) is structurally
 * impossible, not just discouraged by convention. Not wrapped in an
 * explicit DB::transaction(); DB::afterCommit() runs the closure
 * immediately since there is no active transaction to defer past.
 */
class SignatureCertificateService
{
    public function __construct(
        private readonly SignatureWorkflowTransitionService $transitions,
        private readonly DocumentHashService $hashService,
        private readonly SignatureEventLogger $eventLogger,
    ) {
    }

    public function generate(SignatureRequest $request): CertificateGenerationResult
    {
        if ($request->status !== SignatureRequestStatus::Signed) {
            throw new \RuntimeException(
                "Cannot generate a certificate: request status is '{$request->status->value}', must be 'signed'."
            );
        }

        if ($request->certificate()->exists()) {
            throw new \RuntimeException('A certificate has already been generated for this request.');
        }

        // documents has permanent FORCE ROW LEVEL SECURITY (Section
        // 39A-3C) — $request->document is only touched when the
        // source type is Document, so context is only needed on that
        // branch; generatedDocument reads the (not yet forced)
        // generated_documents table.
        $hash = $request->source_document_type === SignatureSourceDocumentType::Document
            ? (new TenantContextService())->runWithFirmContext($request->firm_id, fn () => $this->hashService->latestForDocument($request->document))
            : $this->hashService->latestForGeneratedDocument($request->generatedDocument);

        if ($hash === null) {
            throw new \RuntimeException('Cannot generate a certificate: no document_hashes row exists for the source document.');
        }

        if ($request->events()->doesntExist()) {
            throw new \RuntimeException('Cannot generate a certificate: no signature_events trail exists for this request.');
        }

        $snapshot = $this->assembleSnapshot($request, $hash);

        $certificate = SignatureCertificate::create([
            'firm_id' => $request->firm_id,
            'signature_request_id' => $request->id,
            'status' => \App\Enums\SignatureCertificateStatus::Generated,
            'certificate_data_json' => $this->snapshotToArray($snapshot),
            'document_hash_id' => $hash->id,
            'generated_at' => now(),
        ]);

        $this->eventLogger->log(
            request: $request,
            eventType: SignatureEventType::CertificateGenerated,
            actorType: SignatureEventActorType::System,
            documentHash: $hash,
        );

        $this->transitions->assertTransitionAllowed($request->status->value, SignatureRequestStatus::Completed->value);
        $request->update(['status' => SignatureRequestStatus::Completed, 'completed_at' => now()]);

        $this->eventLogger->log(
            request: $request,
            eventType: SignatureEventType::RequestCompleted,
            actorType: SignatureEventActorType::System,
        );

        $completedRequest = $request->fresh();

        DB::afterCommit(function () use ($completedRequest) {
            try {
                app(WebhookEventRecorderService::class)->record($completedRequest->firm, WebhookEventType::SignatureCompleted, $completedRequest);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return new CertificateGenerationResult($certificate->id, $certificate->status, $hash->id, $certificate->generated_at);
    }

    private function assembleSnapshot(SignatureRequest $request, DocumentHash $hash): SignatureEvidenceSnapshot
    {
        $recipients = $request->recipients()->get()->map(fn ($r) => [
            'recipientUuid' => $r->uuid,
            'signerName' => $r->signer_name,
            'signerEmail' => $r->signer_email,
            'textVersion' => $r->text_version,
            'consentedAt' => $r->consented_at?->toIso8601String(),
            'signedAt' => $r->signed_at?->toIso8601String(),
        ])->all();

        $eventTrail = $request->events()->orderBy('created_at')->get()->map(fn ($e) => [
            'eventType' => $e->event_type->value,
            'occurredAt' => $e->created_at->toIso8601String(),
        ])->all();

        return new SignatureEvidenceSnapshot(
            signatureRequestId: $request->id,
            documentHashValue: $hash->hash_value,
            hashAlgorithm: $hash->algorithm->value,
            recipients: $recipients,
            eventTrail: $eventTrail,
            assembledAt: now(),
        );
    }

    private function snapshotToArray(SignatureEvidenceSnapshot $snapshot): array
    {
        return [
            'signature_request_id' => $snapshot->signatureRequestId,
            'document_hash_value' => $snapshot->documentHashValue,
            'hash_algorithm' => $snapshot->hashAlgorithm,
            'recipients' => $snapshot->recipients,
            'event_trail' => $snapshot->eventTrail,
            'assembled_at' => $snapshot->assembledAt->toIso8601String(),
        ];
    }
}
