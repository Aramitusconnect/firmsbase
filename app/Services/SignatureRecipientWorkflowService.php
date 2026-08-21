<?php

namespace App\Services;

use App\Enums\SignatureEventActorType;
use App\Enums\SignatureEventType;
use App\Enums\SignatureRequestStatus;
use App\Models\SignatureRequestRecipient;

/**
 * SignatureRecipientWorkflowService — every per-recipient transition
 * goes through here. sign() is only reachable from status=Consented
 * (enforced by SignatureWorkflowTransitionService's graph — sent/
 * viewed cannot jump directly to signed) — this is the STRUCTURAL
 * enforcement of "consent must be captured before signature
 * execution," not a separate ad hoc check.
 *
 * consent() is the concrete Phase 6 reuse point: it delegates to
 * SignatureEventLogger::logConsentCaptured(), which itself calls the
 * existing AcknowledgmentSignatureFoundationService::record().
 *
 * Every transition calls SignatureRequestAggregationService afterward
 * so the parent request's aggregate status stays correct.
 *
 * Section 39A-7 Wave 7: signature_requests/signature_request_recipients/
 * signature_events/signature_certificates are now FORCE RLS protected.
 * Each of the 5 methods below wraps its ENTIRE body — the recipient
 * ->update(), the eventLogger->log()/logConsentCaptured() call, the
 * nested $this->aggregation->recompute() call, and the method's own
 * trailing ->fresh() — in ONE shared runWithFirmContext() call, keyed
 * on $recipient->firm_id (the row actually being mutated). This matches
 * the "one shared wrap covering a fixed multi-statement unit"
 * precedent, since SignatureRequestAggregationService::recompute()'s
 * own internal work is bounded (a handful of statements, never
 * proportional to recipient count), not an unbounded loop.
 * recompute()/advanceStepwiseTo()/advanceTo() themselves MUST remain
 * completely plain and never self-wrap with runWithFirmContext() —
 * doing so would reproduce the exact nested-wrap/"decoy wrap" bug
 * FormReviewService::resubmitAfterRevision() had to work around: the
 * inner self-wrap's finally block would clear the outer caller's
 * context the instant recompute() returns, corrupting the caller's own
 * subsequent statements. SignatureEventLogger::log()/logConsentCaptured()
 * must remain plain/leaf for the identical reason — both are always
 * called from inside an already-established caller wrap.
 *
 * Non-payment completion program: sign() closes the request→certificate
 * gap by calling SignatureCertificateService::generate() once the
 * parent SignatureRequest's recomputed status is Signed. That call is
 * made AFTER sign()'s own runWithFirmContext() wrap above has fully
 * returned — never from inside it — for the identical nesting reason
 * documented in the paragraph above: generate() itself opens four of
 * its own sibling runWithFirmContext() calls, and nesting those inside
 * sign()'s outer wrap would reproduce the same decoy-wrap bug (the
 * inner finally clearing app.current_firm_id out from under the
 * outer wrap's remaining statements). The SignatureRequest instance
 * passed to generate() is captured from inside the wrap (via
 * recompute()'s own return value) while context was active, then used
 * as a plain PHP object afterward — generate() re-establishes its own
 * context from $request->firm_id and needs no ambient context from the
 * caller.
 */
class SignatureRecipientWorkflowService
{
    public function __construct(
        private readonly SignatureWorkflowTransitionService $transitions,
        private readonly SignatureEventLogger $eventLogger,
        private readonly SignatureRequestAggregationService $aggregation,
        private readonly SignatureCertificateService $certificates,
    ) {}

    public function view(SignatureRequestRecipient $recipient, string $ipAddress, string $userAgent): SignatureRequestRecipient
    {
        $this->transitions->assertTransitionAllowed($recipient->status->value, SignatureRequestStatus::Viewed->value);

        return (new TenantContextService)->runWithFirmContext($recipient->firm_id, function () use ($recipient, $ipAddress, $userAgent) {
            $recipient->update(['status' => SignatureRequestStatus::Viewed, 'viewed_at' => now()]);

            $this->eventLogger->log(
                request: $recipient->signatureRequest,
                eventType: SignatureEventType::RecipientViewed,
                actorType: SignatureEventActorType::Recipient,
                actorRecipient: $recipient,
                recipient: $recipient,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            $this->aggregation->recompute($recipient->signatureRequest);

            return $recipient->fresh();
        });
    }

    /**
     * $acknowledgerType/$acknowledgerId identify the real linked entity
     * (e.g. 'App\Models\Client', $client->id) where one exists, or the
     * recipient's own class/id when none does.
     */
    public function consent(
        SignatureRequestRecipient $recipient,
        string $acknowledgerType,
        int $acknowledgerId,
        string $textVersion,
        string $ipAddress,
        string $userAgent,
    ): SignatureRequestRecipient {
        $this->transitions->assertTransitionAllowed($recipient->status->value, SignatureRequestStatus::Consented->value);

        return (new TenantContextService)->runWithFirmContext($recipient->firm_id, function () use ($recipient, $acknowledgerType, $acknowledgerId, $textVersion, $ipAddress, $userAgent) {
            $request = $recipient->signatureRequest;

            $this->eventLogger->logConsentCaptured(
                request: $request,
                recipient: $recipient,
                acknowledgerType: $acknowledgerType,
                acknowledgerId: $acknowledgerId,
                textVersion: $textVersion,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            $recipient->update([
                'status' => SignatureRequestStatus::Consented,
                'text_version' => $textVersion,
                'consented_at' => now(),
            ]);

            $this->aggregation->recompute($request);

            return $recipient->fresh();
        });
    }

    /**
     * Only reachable from Consented — the transition graph itself
     * enforces consent-before-execution.
     */
    public function sign(SignatureRequestRecipient $recipient): SignatureRequestRecipient
    {
        if (! $recipient->hasConsented()) {
            throw new \RuntimeException('Cannot sign: this recipient has not captured consent.');
        }

        $this->transitions->assertTransitionAllowed($recipient->status->value, SignatureRequestStatus::Signed->value);

        $requestReadyForCertificate = null;

        $signedRecipient = (new TenantContextService)->runWithFirmContext($recipient->firm_id, function () use ($recipient, &$requestReadyForCertificate) {
            $recipient->update(['status' => SignatureRequestStatus::Signed, 'signed_at' => now()]);

            $this->eventLogger->log(
                request: $recipient->signatureRequest,
                eventType: SignatureEventType::RecipientSigned,
                actorType: SignatureEventActorType::Recipient,
                actorRecipient: $recipient,
                recipient: $recipient,
            );

            $recomputedRequest = $this->aggregation->recompute($recipient->signatureRequest);

            if ($recomputedRequest->status === SignatureRequestStatus::Signed) {
                $requestReadyForCertificate = $recomputedRequest;
            }

            return $recipient->fresh();
        });

        // Sibling to the wrap above, never nested inside it — see this
        // class's own docblock. $requestReadyForCertificate is only
        // non-null when every active recipient has now signed, so
        // generate() is called at most once per unanimous completion;
        // its own DB-unique constraint on signature_certificates.signature_request_id
        // makes a second certificate for the same request structurally
        // impossible regardless.
        if ($requestReadyForCertificate !== null) {
            $this->certificates->generate($requestReadyForCertificate);
        }

        return $signedRecipient;
    }

    /**
     * A decline cascades the same terminal state to the whole request
     * (see SignatureRequestAggregationService) — no partial-completion
     * policy is invented here.
     */
    public function decline(SignatureRequestRecipient $recipient, string $reason): SignatureRequestRecipient
    {
        $this->transitions->assertTransitionAllowed($recipient->status->value, SignatureRequestStatus::Declined->value);

        return (new TenantContextService)->runWithFirmContext($recipient->firm_id, function () use ($recipient, $reason) {
            $recipient->update([
                'status' => SignatureRequestStatus::Declined,
                'declined_at' => now(),
                'declined_reason' => $reason,
            ]);

            $this->eventLogger->log(
                request: $recipient->signatureRequest,
                eventType: SignatureEventType::RecipientDeclined,
                actorType: SignatureEventActorType::Recipient,
                actorRecipient: $recipient,
                recipient: $recipient,
                metadata: ['reason' => $reason],
            );

            $this->aggregation->recompute($recipient->signatureRequest);

            return $recipient->fresh();
        });
    }

    public function expire(SignatureRequestRecipient $recipient): SignatureRequestRecipient
    {
        $this->transitions->assertTransitionAllowed($recipient->status->value, SignatureRequestStatus::Expired->value);

        return (new TenantContextService)->runWithFirmContext($recipient->firm_id, function () use ($recipient) {
            $recipient->update(['status' => SignatureRequestStatus::Expired]);

            $this->eventLogger->log(
                request: $recipient->signatureRequest,
                eventType: SignatureEventType::RecipientExpired,
                actorType: SignatureEventActorType::System,
                recipient: $recipient,
            );

            $this->aggregation->recompute($recipient->signatureRequest);

            return $recipient->fresh();
        });
    }
}
