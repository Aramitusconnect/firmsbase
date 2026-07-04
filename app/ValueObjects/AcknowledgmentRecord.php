<?php

namespace App\ValueObjects;

/**
 * AcknowledgmentRecord — the Phase 6 signature-request FOUNDATION only
 * (approved decision: no signature_requests table, no e-signature
 * provider, no signer workflow, no Phase 11 work in this phase). This
 * value object defines the shape an acknowledgment takes so that
 * Phase 11 can build a full signature-request workflow against a
 * stable contract rather than inventing an incompatible second shape
 * later. It is deliberately NOT persisted by
 * AcknowledgmentSignatureFoundationService itself — callers that need
 * to keep a durable record embed this VO's fields into whatever
 * audit/event table they already own (e.g. license_events.metadata,
 * firm_activation_events.metadata_json), rather than Phase 6 inventing
 * a new table for it.
 */
final readonly class AcknowledgmentRecord
{
    public function __construct(
        public string $acknowledgerType,
        public int $acknowledgerId,
        public string $textVersion,
        public bool $acknowledged,
        public \DateTimeInterface $acknowledgedAt,
    ) {
    }
}
