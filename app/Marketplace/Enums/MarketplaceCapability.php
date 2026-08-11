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
 * exist here as vocabulary only (section 18's own listed future
 * capabilities) — Mission 2 does not implement the behavior behind
 * them (section 65/94: no AI Intake, no consultation/scheduling/lead
 * workflow in this mission). `MarketplaceCapabilityService` never
 * grants these today; their presence in this enum is what lets Mission
 * 3 wire them in as a change to that one service, not a new enum or a
 * new scattered check.
 */
enum MarketplaceCapability: string
{
    case ProfileManagement = 'profile_management';
    case ClaimManagement = 'claim_management';
    case MemberBadge = 'member_badge';

    // Reserved for Mission 3 — never granted by MarketplaceCapabilityService today.
    case ConsultationRequests = 'consultation_requests';
    case SecureIntake = 'secure_intake';
    case Scheduling = 'scheduling';
    case LeadDelivery = 'lead_delivery';
}
