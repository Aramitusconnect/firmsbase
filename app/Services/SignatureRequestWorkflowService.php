<?php

namespace App\Services;

use App\Enums\ConsentChannel;
use App\Enums\SignatureEventActorType;
use App\Enums\SignatureEventType;
use App\Enums\SignatureRecipientType;
use App\Enums\SignatureRequestStatus;
use App\Enums\SignatureSourceDocumentType;
use App\Models\Client;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\GeneratedDocument;
use App\Models\Matter;
use App\Models\SignatureRequest;
use App\Models\SignatureRequestRecipient;
use App\Notifications\TemplatedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

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
    ) {}

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

        return (new TenantContextService)->runWithFirmContext($firm->id, function () use ($firm, $title, $requestedBy, $document, $generatedDocument, $matter, $client, $expiresAt) {
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
     * The only place a signature_request_recipients row is created.
     * Extracted from RecipientsRelationManager's original inline
     * SignatureRequestRecipient::create() call (Governance Section 25+
     * WorkflowTransitionEnforcementSearchTest requires every direct
     * write of a catalog workflow status enum — SignatureRequestStatus
     * included — to live in app/Services, never in a UI/Filament
     * layer) — recipients are only ever created at Draft, mirroring
     * create()'s own status write above for the parent request.
     * $clientId is looked up (not trusted as already firm-scoped) the
     * same way the original inline implementation did, inside this
     * method's own tenant context wrap.
     */
    public function addRecipient(
        SignatureRequest $request,
        FirmUser $actor,
        SignatureRecipientType $recipientType,
        string $signerName,
        string $signerEmail,
        ?int $clientId = null,
    ): SignatureRequestRecipient {
        if (! $this->accessPolicy->canManageRequests($actor)) {
            throw new \RuntimeException('Actor role is not permitted to manage recipients for this signature request.');
        }

        return (new TenantContextService)->runWithFirmContext($request->firm_id, function () use ($request, $actor, $recipientType, $signerName, $signerEmail, $clientId) {
            $fresh = SignatureRequest::query()->where('id', $request->id)->firstOrFail();

            if ((int) $actor->firm_id !== (int) $fresh->firm_id || $fresh->status !== SignatureRequestStatus::Draft) {
                throw new \RuntimeException('This request can no longer accept new recipients.');
            }

            $resolvedClientId = null;

            if ($recipientType === SignatureRecipientType::Client && $clientId !== null) {
                $resolvedClientId = Client::query()->where('id', $clientId)->where('firm_id', $fresh->firm_id)->value('id');
            }

            return SignatureRequestRecipient::create([
                'signature_request_id' => $fresh->id,
                'firm_id' => $fresh->firm_id,
                'recipient_type' => $recipientType,
                'client_id' => $resolvedClientId,
                'signer_name' => $signerName,
                'signer_email' => $signerEmail,
                'status' => SignatureRequestStatus::Draft,
            ]);
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

        return (new TenantContextService)->runWithFirmContext($request->firm_id, function () use ($request, $reviewer, $notes) {
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

        $hasNoRecipients = (new TenantContextService)->runWithFirmContext(
            $request->firm_id,
            fn () => $request->recipients()->doesntExist(),
        );

        if ($hasNoRecipients) {
            throw new \RuntimeException('Cannot send: this request has no recipients.');
        }

        $this->transitions->assertTransitionAllowed($request->status->value, SignatureRequestStatus::Sent->value);

        (new TenantContextService)->runWithFirmContext(
            $request->firm_id,
            fn () => $request->update(['status' => SignatureRequestStatus::Sent, 'sent_at' => now()]),
        );

        (new TenantContextService)->runWithFirmContext(
            $request->firm_id,
            fn () => $request->load('recipients'),
        );

        foreach ($request->recipients as $recipient) {
            // Non-payment completion program, e-signature signer-facing
            // flow: a fresh, per-recipient CSPRNG access token is minted
            // exactly once, right here, the moment a recipient's status
            // moves to Sent — mirroring
            // ProviderConnectionService::generateRawWebhookRoutingToken()'s
            // own token-generation shape byte-for-byte
            // (rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')).
            // $rawToken lives ONLY in this loop iteration's local PHP
            // memory: only hash('sha256', $rawToken) is ever persisted
            // (access_token_hash), and the raw value is used exactly
            // once more, immediately below, to build the signer link
            // embedded in the outbound notification body — never
            // logged, never returned to the caller, never stored
            // anywhere else. A recipient whose status is already past
            // Sent (defensive; should not occur inside this freshly
            // Sent-transitioned loop) is skipped rather than re-minted,
            // so a token is never silently rotated out from under an
            // already-issued link.
            $rawToken = $this->generateRawRecipientAccessToken();
            $tokenHash = hash('sha256', $rawToken);

            (new TenantContextService)->runWithFirmContext(
                $recipient->firm_id,
                fn () => $recipient->update([
                    'status' => SignatureRequestStatus::Sent,
                    'access_token_hash' => $tokenHash,
                ]),
            );

            $this->deliverSignerLink($request, $recipient, $rawToken);
        }

        (new TenantContextService)->runWithFirmContext($request->firm_id, fn () => $this->eventLogger->log(
            request: $request,
            eventType: SignatureEventType::RequestSent,
            actorType: SignatureEventActorType::FirmUser,
            actorFirmUser: $actor,
        ));

        return (new TenantContextService)->runWithFirmContext($request->firm_id, fn () => $request->fresh());
    }

    /**
     * Token-generation template lifted verbatim from
     * ProviderConnectionService::generateRawWebhookRoutingToken() — a
     * 256-bit CSPRNG value, base64url-encoded with no padding. Private:
     * the raw value this returns must never leave send()'s own call
     * stack except via the one-shot embed inside deliverSignerLink().
     */
    private function generateRawRecipientAccessToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Best-effort only, mirroring SendInvoiceAction::dispatchSentNotification()'s
     * identical "runs after the real state transition already committed,
     * a delivery failure must never surface as if send() itself failed"
     * shape. Deliberately does NOT go through
     * NotificationDispatchService::dispatch()/DispatchNotificationJob:
     * that job is a QUEUED job (ShouldQueue, backed by the database
     * queue driver in this deployment), so its payload is persisted to
     * the `jobs` table between enqueue and worker pickup — embedding
     * $rawToken in that payload would violate the hard rule that the
     * raw token is never persisted anywhere beyond this method's own
     * call stack and the notification body actually being transmitted.
     * Instead, this reuses the SAME underlying collaborators
     * DispatchNotificationJob itself calls internally
     * (NotificationTemplateService::resolve(),
     * SenderDomainVerificationService::isVerified(),
     * NotificationEligibilityService::check(),
     * OutboundMailCorrelationService::correlate() + TemplatedNotification
     * for the real, correlated Mail transport), invoked SYNCHRONOUSLY
     * so the raw token exists only for the duration of this one send —
     * never queued, never written to any table. NotificationDispatchService's
     * own public recordSent()/recordFailed() are still called afterward
     * so this delivery attempt lands in the same notification_events
     * audit trail every other Mission 6 dispatch does.
     *
     * Scope, stated plainly (a real, deliberately-deferred gap, not an
     * oversight): only fires when the recipient resolves to a real,
     * firm-owned Client — the only case NotificationEligibilityService::check()
     * can evaluate consent/do-not-contact/suppression against, since
     * that service (and NotificationDispatchService::dispatch() itself)
     * both hard-require a non-null Client. A Contact/Party/FirmUser/
     * External recipient (SignatureRecipientType — a recipient with no
     * linked Client row) still gets a real access token minted and
     * stored above, and the link remains fully valid; it is simply not
     * auto-emailed by this pass. Firm staff can relay the link
     * out-of-band today; a future workstream may extend
     * NotificationEligibilityService to a nullable-Client shape to close
     * this gap without weakening consent enforcement for real clients.
     */
    private function deliverSignerLink(SignatureRequest $request, SignatureRequestRecipient $recipient, string $rawToken): void
    {
        if ($recipient->client_id === null || ! filled($recipient->signer_email)) {
            return;
        }

        (new TenantContextService)->runWithFirmContext($request->firm_id, function () use ($request, $recipient, $rawToken) {
            $firm = $request->firm;
            $client = Client::query()->where('id', $recipient->client_id)->where('firm_id', $firm->id)->first();

            if ($client === null) {
                return;
            }

            $channel = ConsentChannel::Email;
            $recipientEmail = $recipient->signer_email;
            $correlationId = (string) Str::uuid();
            $dispatcher = app(NotificationDispatchService::class);

            $template = app(NotificationTemplateService::class)->resolve(
                $firm,
                'signature_request_sent',
                $channel,
                $client->preferred_language ?? 'en',
            );

            if ($template === null) {
                $dispatcher->recordFailed($firm, $correlationId, $channel, $recipientEmail, null, 'no active notification template found for key=signature_request_sent');

                return;
            }

            if (! app(SenderDomainVerificationService::class)->isVerified($template)) {
                $dispatcher->recordFailed($firm, $correlationId, $channel, $recipientEmail, $template->id, 'sender domain not verified: '.($template->from_domain ?? 'unknown domain'));

                return;
            }

            $eligibility = app(NotificationEligibilityService::class)->check($firm, $client, $channel, $recipientEmail);

            if (! $eligibility->eligible) {
                $dispatcher->recordFailed($firm, $correlationId, $channel, $recipientEmail, $template->id, (string) $eligibility->reason);

                return;
            }

            // The raw token appears ONLY in this locally-built string,
            // passed straight into a synchronous (never-queued)
            // Notification send below — it is never assigned to any
            // property, job, cache entry, or log call.
            $signerLink = route('public.signature-recipients.show', ['uuid' => $recipient->uuid]).'?token='.$rawToken;
            $body = $template->body."\n\n".$signerLink;

            try {
                app(OutboundMailCorrelationService::class)->correlate(
                    $firm,
                    $channel,
                    $recipientEmail,
                    fn (string $mailCorrelationId) => Notification::route('mail', $recipientEmail)->notify(
                        (new TemplatedNotification($template->subject, $body))->withCorrelationId($mailCorrelationId)
                    ),
                );

                $dispatcher->recordSent($firm, $correlationId, $channel, $recipientEmail, $template->id, $client->id, $request->matter_id);
            } catch (Throwable $e) {
                report($e);

                Log::warning('signature_request_send_signer_link_dispatch_failed', [
                    'firm_id' => $firm->id,
                    'signature_request_id' => $request->id,
                    'signature_request_recipient_id' => $recipient->id,
                    'notification_template_id' => $template->id,
                    'exception' => $e::class,
                ]);

                $dispatcher->recordFailed($firm, $correlationId, $channel, $recipientEmail, $template->id, 'transport failed: '.$e::class);
            }
        });
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

        (new TenantContextService)->runWithFirmContext(
            $request->firm_id,
            fn () => $request->update(['status' => SignatureRequestStatus::Voided, 'voided_at' => now()]),
        );

        (new TenantContextService)->runWithFirmContext(
            $request->firm_id,
            fn () => $request->load('recipients'),
        );

        foreach ($request->recipients as $recipient) {
            if ($this->transitions->isTransitionAllowed($recipient->status->value, SignatureRequestStatus::Voided->value)) {
                (new TenantContextService)->runWithFirmContext(
                    $recipient->firm_id,
                    fn () => $recipient->update(['status' => SignatureRequestStatus::Voided, 'voided_at' => now()]),
                );
            }
        }

        (new TenantContextService)->runWithFirmContext($request->firm_id, fn () => $this->eventLogger->log(
            request: $request,
            eventType: SignatureEventType::RequestVoided,
            actorType: SignatureEventActorType::FirmUser,
            actorFirmUser: $actor,
            metadata: ['reason' => $reason],
        ));

        return (new TenantContextService)->runWithFirmContext($request->firm_id, fn () => $request->fresh());
    }
}
