<?php

namespace App\Services;

use App\ValueObjects\AcknowledgmentRecord;

/**
 * AcknowledgmentSignatureFoundationService — the Phase 6 signature-
 * request FOUNDATION only (approved decision: no signature_requests
 * table, no e-signature provider, no signer workflow, no Phase 11 work
 * in this phase). record() is a PURE construction of an
 * AcknowledgmentRecord value object — it does not persist anything
 * itself. Callers that need a durable record embed the returned VO's
 * fields into whatever audit/event table they already own (e.g.
 * license_events.metadata, firm_activation_events.metadata_json)
 * rather than this service inventing a new table.
 */
class AcknowledgmentSignatureFoundationService
{
    public function record(
        string $acknowledgerType,
        int $acknowledgerId,
        string $textVersion,
        bool $acknowledged = true,
        ?\DateTimeInterface $acknowledgedAt = null,
    ): AcknowledgmentRecord {
        return new AcknowledgmentRecord(
            acknowledgerType: $acknowledgerType,
            acknowledgerId: $acknowledgerId,
            textVersion: $textVersion,
            acknowledged: $acknowledged,
            acknowledgedAt: $acknowledgedAt ?? now(),
        );
    }
}
