<?php

declare(strict_types=1);

namespace App\Marketplace\Enums;

/**
 * MarketplaceAnalyticsEventType — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 13. The closed vocabulary of privacy-conscious
 * aggregate marketplace usage events — deliberately small: real
 * product-usage signal (what gets viewed, what gets searched for),
 * never a granular UI-interaction/clickstream taxonomy that would
 * pressure future additions toward capturing more about the visitor
 * than "a page was viewed" or "a search happened."
 */
enum MarketplaceAnalyticsEventType: string
{
    case FirmProfileViewed = 'firm_profile_viewed';
    case AttorneyProfileViewed = 'attorney_profile_viewed';
    case SearchPerformed = 'search_performed';

    /**
     * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 14. The
     * intake funnel's own aggregate-only stage counters — matches this
     * enum's own established privacy bar (no prospect name/email/
     * phone/structured_data, ever). subject is the intake's own
     * DirectoryFirm when known, letting the same top-viewed-firms-style
     * query answer "which firms get the most MyAttorney traffic"
     * without adding a new query shape.
     */
    case IntakeStarted = 'intake_started';
    case IntakeSubmitted = 'intake_submitted';
    case IntakeAccepted = 'intake_accepted';
    case IntakeDeclined = 'intake_declined';
    case IntakeConverted = 'intake_converted';
}
