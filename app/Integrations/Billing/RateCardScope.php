<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

/**
 * RateCardScope — the three precedence tiers a `provider_rate_card_entries`
 * row may occupy (checkpoint4-design-cost-control.md §2 step 6):
 * `platform_default` < `package_default` < `firm_override`. Implemented
 * as a method (not array order), mirroring
 * `App\Enums\EntitlementSource::precedence()`'s exact shape, so the
 * ranking is directly testable and cannot silently drift if cases are
 * ever reordered.
 */
enum RateCardScope: string
{
    case PlatformDefault = 'platform_default';
    case PackageDefault = 'package_default';
    case FirmOverride = 'firm_override';

    public function precedence(): int
    {
        return match ($this) {
            self::PlatformDefault => 1,
            self::PackageDefault => 2,
            self::FirmOverride => 3,
        };
    }
}
