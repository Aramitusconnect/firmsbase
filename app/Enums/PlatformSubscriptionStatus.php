<?php

namespace App\Enums;

/**
 * PlatformSubscriptionStatus — platform_subscriptions.status. Proposed
 * during Phase 6 planning and approved. Distinct from LicenseStatus
 * (which governs the firm's overall commercial/legal-data-access
 * standing) — this enum only tracks the platform billing subscription
 * record itself.
 */
enum PlatformSubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
