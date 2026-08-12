<?php

declare(strict_types=1);

namespace App\Marketplace\Enums;

/**
 * MarketplaceCapability — Mission 2 (MyAttorney Marketplace Core),
 * section 67: "avoid scattering checks such as
 * `if ($firm->subscription_plan === 'x')` through the UI. Create
 * canonical marketplace capabilities." Every capability check in this
 * codebase must go through `MarketplaceCapabilityService`, never test
 * `DirectoryFirm::profileLevel()` or a subscription/billing field
 * directly at the call site.
 *
 * `ConsultationRequests`/`SecureIntake`/`Scheduling`/`LeadDelivery`
 * existed here as vocabulary only (section 18's own listed future
 * capabilities) — Mission 2 did not implement the behavior behind
 * them. Mission 3, checkpoint 3 is the first of these to actually be
 * granted: `SecureIntake` is now wired into
 * `MarketplaceCapabilityService::LEVEL_CAPABILITIES` (claimed_profile
 * and verified_member). `ConsultationRequests`/`Scheduling`/
 * `LeadDelivery` remain reserved-only, for later checkpoints.
 */
enum MarketplaceCapability: string
{
    case ProfileManagement = 'profile_management';
    case ClaimManagement = 'claim_management';
    case MemberBadge = 'member_badge';
    case SecureIntake = 'secure_intake';

    // Still reserved — never granted by MarketplaceCapabilityService today.
    case ConsultationRequests = 'consultation_requests';
    case Scheduling = 'scheduling';
    case LeadDelivery = 'lead_delivery';
}
