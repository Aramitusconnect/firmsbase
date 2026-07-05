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
 */
class SignatureRecipientWorkflowService
{
    public function __construct(
        private readonly SignatureWorkflowTransitionService $transitions,
        private readonly SignatureEventLogger $eventLogger,
        private readonly SignatureRequestAggregationService $aggregation,
    ) {
    }

    public function view(SignatureRequestRecipient $recipient, string $ipAddress, string $userAgent): SignatureRequestRecipient
    {
        $this->transitions->assertTransitionAllowed($recipient->status->value, SignatureRequestStatus::Viewed->value);

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

        $recipient->update(['status' => SignatureRequestStatus::Signed, 'signed_at' => now()]);

        $this->eventLogger->log(
            request: $recipient->signatureRequest,
            eventType: SignatureEventType::RecipientSigned,
            actorType: SignatureEventActorType::Recipient,
            actorRecipient: $recipient,
            recipient: $recipient,
        );

        $this->aggregation->recompute($recipient->signatureRequest);

        return $recipient->fresh();
    }

    /**
     * A decline cascades the same terminal state to the whole request
     * (see SignatureRequestAggregationService) — no partial-completion
     * policy is invented here.
     */
    public function decline(SignatureRequestRecipient $recipient, string $reason): SignatureRequestRecipient
    {
        $this->transitions->assertTransitionAllowed($recipient->status->value, SignatureRequestStatus::Declined->value);

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
    }

    public function expire(SignatureRequestRecipient $recipient): SignatureRequestRecipient
    {
        $this->transitions->assertTransitionAllowed($recipient->status->value, SignatureRequestStatus::Expired->value);

        $recipient->update(['status' => SignatureRequestStatus::Expired]);

        $this->eventLogger->log(
            request: $recipient->signatureRequest,
            eventType: SignatureEventType::RecipientExpired,
            actorType: SignatureEventActorType::System,
            recipient: $recipient,
        );

        $this->aggregation->recompute($recipient->signatureRequest);

        return $recipient->fresh();
    }
}
