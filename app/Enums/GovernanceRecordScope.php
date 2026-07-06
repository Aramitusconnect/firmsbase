<?php

namespace App\Enums;

/**
 * GovernanceRecordScope — the closed set of long-term LOG FAMILIES that
 * AuditPreservationPolicyService declares as protected (project rule:
 * "never silently delete legal, audit, trust, payment, document-access,
 * support-access, client-portal, AI, or platform billing records").
 * This is deliberately distinct from RetentionRecordType (which scopes
 * a retention_policies row to a DATA record family) and LegalHoldScope
 * (which scopes a legal_holds row to a fixed hierarchy level) — each of
 * the three enums has a single, non-overlapping job.
 *
 * ClientPortalLog is included per approved decision #8 even though no
 * client-portal-log table was confirmed to exist during Phase 17
 * research — AuditPreservationPolicyService represents it as a required
 * future log family gap rather than inventing a table for it.
 */
enum GovernanceRecordScope: string
{
    case SecurityLog = 'security_log';
    case PaymentLog = 'payment_log';
    case TrustLog = 'trust_log';
    case DocumentAccessLog = 'document_access_log';
    case SupportAccessLog = 'support_access_log';
    case ClientPortalLog = 'client_portal_log';
    case PlatformBillingLog = 'platform_billing_log';
    case AiLog = 'ai_log';
    case ApiLog = 'api_log';
    case WebhookLog = 'webhook_log';
}
