<?php

namespace App\Enums;

/**
 * SignatureEventType — the append-only signature_events ledger's event
 * vocabulary. ConsentCaptured is the event that also carries the
 * Phase-6-compatible acknowledger_type/acknowledger_id/text_version/
 * acknowledged/acknowledged_at columns (see SignatureEventLogger).
 */
enum SignatureEventType: string
{
    case RequestCreated = 'request_created';
    case RequestAttorneyReviewed = 'request_attorney_reviewed';
    case RequestSent = 'request_sent';
    case RecipientViewed = 'recipient_viewed';
    case ConsentCaptured = 'consent_captured';
    case RecipientSigned = 'recipient_signed';
    case RecipientDeclined = 'recipient_declined';
    case RecipientExpired = 'recipient_expired';
    case RequestCompleted = 'request_completed';
    case RequestVoided = 'request_voided';
    case RequestExpired = 'request_expired';
    case CertificateGenerated = 'certificate_generated';
    case DocumentHashRecorded = 'document_hash_recorded';
}
