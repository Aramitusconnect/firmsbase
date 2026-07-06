<?php

namespace App\Enums;

/**
 * AiApprovalEventType — ai_approval_events.event_type. Append-only log
 * of every transition on an ai_approval_requests row (mirrors
 * TrustApprovalEventType/WebhookEvent's immutable-log reasoning).
 */
enum AiApprovalEventType: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
