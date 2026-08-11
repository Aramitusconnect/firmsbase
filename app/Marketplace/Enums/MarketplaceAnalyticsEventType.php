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
}
