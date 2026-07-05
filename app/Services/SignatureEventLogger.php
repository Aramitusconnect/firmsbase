<?php

namespace App\Services;

use App\Enums\SignatureEventActorType;
use App\Enums\SignatureEventType;
use App\Models\DocumentHash;
use App\Models\FirmUser;
use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use App\Models\SignatureRequestRecipient;

/**
 * SignatureEventLogger — the single, narrow choke point for every
 * signature_events write. A deliberate deviation from Phase 10's
 * "each service privately logs its own events" pattern: evidentiary
 * logging benefits from one auditable writer, given the higher legal
 * stakes of signature evidence.
 *
 * logConsentCaptured() is the concrete, checkable reuse of the Phase 6
 * signature-request foundation: it calls the EXISTING, unmodified
 * AcknowledgmentSignatureFoundationService::record() to build an
 * AcknowledgmentRecord, then persists that VO's fields under their
 * exact Phase 6 names (acknowledger_type, acknowledger_id, text_version,
 * acknowledged, acknowledged_at) onto the consent_captured row. Neither
 * AcknowledgmentRecord.php nor AcknowledgmentSignatureFoundationService.php
 * is modified anywhere in Phase 11.
 */
class SignatureEventLogger
{
    public function __construct(
        private readonly AcknowledgmentSignatureFoundationService $acknowledgmentService,
    ) {
    }

    public function log(
        SignatureRequest $request,
        SignatureEventType $eventType,
        SignatureEventActorType $actorType,
        ?FirmUser $actorFirmUser = null,
        ?SignatureRequestRecipient $actorRecipient = null,
        ?SignatureRequestRecipient $recipient = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?DocumentHash $documentHash = null,
        array $metadata = [],
    ): SignatureEvent {
        return SignatureEvent::create([
            'firm_id' => $request->firm_id,
            'signature_request_id' => $request->id,
            'signature_request_recipient_id' => $recipient?->id,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_firm_user_id' => $actorFirmUser?->id,
            'actor_recipient_id' => $actorRecipient?->id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'document_hash_id' => $documentHash?->id,
            'metadata_json' => $metadata,
            'created_at' => now(),
        ]);
    }

    /**
     * $acknowledgerType is the fully-qualified class name of the
     * linked entity where one exists (e.g. 'App\Models\Client'), or the
     * recipient's own class when none does — matching the exact
     * morph-style convention the Phase 6 test file demonstrates
     * ('App\Models\User').
     */
    public function logConsentCaptured(
        SignatureRequest $request,
        SignatureRequestRecipient $recipient,
        string $acknowledgerType,
        int $acknowledgerId,
        string $textVersion,
        string $ipAddress,
        string $userAgent,
    ): SignatureEvent {
        $record = $this->acknowledgmentService->record($acknowledgerType, $acknowledgerId, $textVersion, true);

        return SignatureEvent::create([
            'firm_id' => $request->firm_id,
            'signature_request_id' => $request->id,
            'signature_request_recipient_id' => $recipient->id,
            'event_type' => SignatureEventType::ConsentCaptured,
            'actor_type' => SignatureEventActorType::Recipient,
            'actor_recipient_id' => $recipient->id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'acknowledger_type' => $record->acknowledgerType,
            'acknowledger_id' => $record->acknowledgerId,
            'text_version' => $record->textVersion,
            'acknowledged' => $record->acknowledged,
            'acknowledged_at' => $record->acknowledgedAt,
            'created_at' => now(),
        ]);
    }
}
