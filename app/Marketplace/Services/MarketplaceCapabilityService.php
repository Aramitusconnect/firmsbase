<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Enums\DirectoryFirmProfileLevel;
use App\Marketplace\Enums\MarketplaceCapability;
use App\Marketplace\Models\DirectoryFirm;

/**
 * MarketplaceCapabilityService — Mission 2 (MyAttorney Marketplace
 * Core), section 67. The single place a capability check is decided.
 * Every controller/Blade/Livewire/Filament call site must call
 * `has()`/`capabilitiesFor()` here — never test `profileLevel()`,
 * `is_claimed`, `is_marketplace_member`, or a subscription/billing
 * field directly. Deliberately separates:
 *   PROFILE LEVEL (DirectoryFirm::profileLevel())
 *   from CAPABILITIES (this service)
 *   from SUBSCRIPTION/BILLING (out of this service's concern entirely
 *     — a claimed/member Firm's marketplace capabilities never key
 *     off a billing_accounts/platform_subscriptions row; membership
 *     activation is the marketplace-specific gate, see
 *     DirectoryFirm::is_marketplace_member).
 *
 * An unclaimed Public Listing has zero capabilities (section 16: "Do
 * NOT provide unclaimed listings with privileged marketplace
 * capabilities").
 */
class MarketplaceCapabilityService
{
    private const LEVEL_CAPABILITIES = [
        'public_listing' => [],
        'claimed_profile' => [
            MarketplaceCapability::ProfileManagement,
            MarketplaceCapability::ClaimManagement,
        ],
        'verified_member' => [
            MarketplaceCapability::ProfileManagement,
            MarketplaceCapability::ClaimManagement,
            MarketplaceCapability::MemberBadge,
        ],
    ];

    /**
     * @return array<int, MarketplaceCapability>
     */
    public function capabilitiesFor(DirectoryFirm $firm): array
    {
        return self::LEVEL_CAPABILITIES[$firm->profileLevel()->value];
    }

    public function has(DirectoryFirm $firm, MarketplaceCapability $capability): bool
    {
        return in_array($capability, $this->capabilitiesFor($firm), true);
    }

    public function profileLevelGrantingCapability(MarketplaceCapability $capability): ?DirectoryFirmProfileLevel
    {
        foreach (self::LEVEL_CAPABILITIES as $level => $capabilities) {
            if (in_array($capability, $capabilities, true)) {
                return DirectoryFirmProfileLevel::from($level);
            }
        }

        return null;
    }
}
