<?php

namespace App\Services;

use App\Enums\SignatureEventActorType;
use App\Enums\SignatureEventType;
use App\Enums\SignatureRequestStatus;
use App\Enums\SignatureSourceDocumentType;
use App\Models\Client;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\GeneratedDocument;
use App\Models\Matter;
use App\Models\SignatureRequest;

/**
 * SignatureRequestWorkflowService — the only place a SignatureRequest
 * is created, attorney-reviewed, sent, or voided. Exactly one of
 * $document / $generatedDocument must be passed to create() — this is
 * the service-enforced XOR behind source_document_type (no DB
 * constraint enforces it, matching the dual-FK pattern established in
 * Phase 10).
 *
 * send() is hard-gated on attorney_reviewed_at being set — this is the
 * literal enforcement of "E-signature is not a substitute for legal
 * review of whether a specific document can be signed electronically":
 * a human attorney/firm-owner sign-off is REQUIRED before a request can
 * ever be sent, not a computed/AI-derived flag.
 *
 * Section 39A-7 Wave 7: signature_requests/signature_request_recipients/
 * signature_events/signature_certificates are now FORCE RLS protected.
 * create() and attorneyReview() each wrap their fixed, small statement
 * set (the row write, the paired eventLogger->log() call, and the
 * method's own trailing ->fresh()) in ONE shared runWithFirmContext()
 * unit, mirroring EmailMessageLinkingService::link()'s "one wrap for a
 * fixed-N-statement unit" precedent. send()/void() are the
 * variable-length-loop case: each gets independent, per-statement
 * wraps (mirroring EmailSyncService::captureMessage()'s per-attachment
 * loop precedent) rather than one shared outer wrap, because a single
 * shared wrap would silently introduce a NEW atomicity guarantee this
 * class has never claimed — it would roll back prior recipients'
 * updates on a later failure, a behavior change this RLS-activation
 * pass must not introduce. Each per-recipient update() inside the
 * loops is keyed on $recipient->firm_id (the row actually being
 * mutated), not $request->firm_id, for the same deterministic-choice
 * reasoning SignatureRecipientWorkflowService's own wraps use. Pure,
 * in-memory checks (canManageRequests(), canReviewAsAttorney(),
 * canVoid(), isAttorneyReviewed(), assertTransitionAllowed()) stay
 * OUTSIDE every wrap.
 */
class SignatureRequestWorkflowService
{
    public function __construct(
        private readonly SignatureWorkflowTransitionService $transitions,
        private readonly SignatureEventLogger $eventLogger,
        private readonly SignatureAndPdfAccessPolicyService $accessPolicy,
    ) {
    }

    public function create(
        Firm $firm,
        string $title,
        FirmUser $requestedBy,
        ?Document $document = null,
        ?GeneratedDocument $generatedDocument = null,
        ?Matter $matter = null,
        ?Client $client = null,
        ?\DateTimeInterface $expiresAt = null,
    ): SignatureRequest {
        if (! $this->accessPolicy->canManageRequests($requestedBy)) {
            throw new \RuntimeException('Actor role is not permitted to create a signature request.');
        }

        if (($document === null) === ($generatedDocument === null)) {
            throw new \RuntimeException('Exactly one of $document or $generatedDocument must be provided.');
        }

        return (new TenantContextService())->runWithFirmContext($firm->id, function () use ($firm, $title, $requestedBy, $document, $generatedDocument, $matter, $client, $expiresAt) {
            $request = SignatureRequest::create([
                'firm_id' => $firm->id,
                'matter_id' => $matter?->id,
                'client_id' => $client?->id,
                'source_document_type' => $document !== null
                    ? SignatureSourceDocumentType::Document
                    : SignatureSourceDocumentType::GeneratedDocument,
                'document_id' => $document?->id,
                'generated_document_id' => $generatedDocument?->id,
                'status' => SignatureRequestStatus::Draft,
                'title' => $title,
                'requested_by_firm_user_id' => $requestedBy->id,
                'expires_at' => $expiresAt,
            ]);

            $this->eventLogger->log(
                request: $request,
                eventType: SignatureEventType::RequestCreated,
                actorType: SignatureEventActorType::FirmUser,
                actorFirmUser: $requestedBy,
            );

            return $request->fresh();
        });
    }

    /**
     * The required human legal-review gate. Must be called, by a
     * FirmOwner/Attorney, before send() will ever succeed.
     */
    public function attorneyReview(SignatureRequest $request, FirmUser $reviewer, string $notes): SignatureRequest
    {
        if (! $this->accessPolicy->canReviewAsAttorney($reviewer)) {
            throw new \RuntimeException('Only a FirmOwner or Attorney may perform the attorney-review sign-off.');
        }

        return (new TenantContextService())->runWithFirmContext($request->firm_id, function () use ($request, $reviewer, $notes) {
            $request->update([
                'attorney_reviewed_at' => now(),
                'attorney_reviewed_by_firm_user_id' => $reviewer->id,
                'attorney_review_notes' => $notes,
            ]);

            $this->eventLogger->log(
                request: $request,
                eventType: SignatureEventType::RequestAttorneyReviewed,
                actorType: SignatureEventActorType::FirmUser,
                actorFirmUser: $reviewer,
            );

            return $request->fresh();
        });
    }

    public function send(SignatureRequest $request, FirmUser $actor): SignatureRequest
    {
        if (! $this->accessPolicy->canManageRequests($actor)) {
            throw new \RuntimeException('Actor role is not permitted to send a signature request.');
        }

        if (! $request->isAttorneyReviewed()) {
            throw new \RuntimeException(
                'Cannot send: this request has not received the required attorney-review sign-off. '.
                'E-signature is not a substitute for legal review of whether this document may be signed electronically.'
            );
        }

        $hasNoRecipients = (new TenantContextService())->runWithFirmContext(
            $request->firm_id,
            fn () => $request->recipients()->doesntExist(),
        );

        if ($hasNoRecipients) {
            throw new \RuntimeException('Cannot send: this request has no recipients.');
        }

        $this->transitions->assertTransitionAllowed($request->status->value, SignatureRequestStatus::Sent->value);

        (new TenantContextService())->runWithFirmContext(
            $request->firm_id,
            fn () => $request->update(['status' => SignatureRequestStatus::Sent, 'sent_at' => now()]),
        );

        (new TenantContextService())->runWithFirmContext(
            $request->firm_id,
            fn () => $request->load('recipients'),
        );

        foreach ($request->recipients as $recipient) {
            (new TenantContextService())->runWithFirmContext(
                $recipient->firm_id,
                fn () => $recipient->update(['status' => SignatureRequestStatus::Sent]),
            );
        }

        (new TenantContextService())->runWithFirmContext($request->firm_id, fn () => $this->eventLogger->log(
            request: $request,
            eventType: SignatureEventType::RequestSent,
            actorType: SignatureEventActorType::FirmUser,
            actorFirmUser: $actor,
        ));

        return (new TenantContextService())->runWithFirmContext($request->firm_id, fn () => $request->fresh());
    }

    /**
     * Voiding cascades to every non-terminal recipient — no dangling
     * active recipient is ever left on a voided request.
     */
    public function void(SignatureRequest $request, FirmUser $actor, string $reason): SignatureRequest
    {
        if (! $this->accessPolicy->canVoid($actor)) {
            throw new \RuntimeException('Actor role is not permitted to void a signature request.');
        }

        $this->transitions->assertTransitionAllowed($request->status->value, SignatureRequestStatus::Voided->value);

        (new TenantContextService())->runWithFirmContext(
            $request->firm_id,
            fn () => $request->update(['status' => SignatureRequestStatus::Voided, 'voided_at' => now()]),
        );

        (new TenantContextService())->runWithFirmContext(
            $request->firm_id,
            fn () => $request->load('recipients'),
        );

        foreach ($request->recipients as $recipient) {
            if ($this->transitions->isTransitionAllowed($recipient->status->value, SignatureRequestStatus::Voided->value)) {
                (new TenantContextService())->runWithFirmContext(
                    $recipient->firm_id,
                    fn () => $recipient->update(['status' => SignatureRequestStatus::Voided, 'voided_at' => now()]),
                );
            }
        }

        (new TenantContextService())->runWithFirmContext($request->firm_id, fn () => $this->eventLogger->log(
            request: $request,
            eventType: SignatureEventType::RequestVoided,
            actorType: SignatureEventActorType::FirmUser,
            actorFirmUser: $actor,
            metadata: ['reason' => $reason],
        ));

        return (new TenantContextService())->runWithFirmContext($request->firm_id, fn () => $request->fresh());
    }
}
