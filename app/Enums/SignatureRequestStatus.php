<?php

namespace App\Enums;

/**
 * SignatureRequestStatus — the exact 9 values from the master plan's
 * Workflow State Machines table (Section 33), reused VERBATIM for both
 * signature_requests.status AND signature_request_recipients.status.
 * The master plan gives exactly one signature lifecycle vocabulary;
 * rather than invent a second, slightly-different recipient-only
 * vocabulary, both the request (aggregate) and each recipient
 * (individual) are tracked using these identical 9 values. See
 * SignatureWorkflowTransitionService for the shared transition graph
 * and SignatureRequestAggregationService for how recipient-level
 * transitions roll up into the request-level status.
 */
enum SignatureRequestStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Viewed = 'viewed';
    case Consented = 'consented';
    case Signed = 'signed';
    case Completed = 'completed';
    case Declined = 'declined';
    case Expired = 'expired';
    case Voided = 'voided';
}
