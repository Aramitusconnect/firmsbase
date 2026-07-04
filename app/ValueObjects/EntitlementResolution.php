<?php

namespace App\ValueObjects;

use App\Enums\EntitlementSource;
use App\Models\FirmEntitlement;

/**
 * EntitlementResolution — the result of EntitlementService::resolve().
 * Carries not just the final enabled/disabled decision but which
 * source won, and that source's settings and underlying record, so
 * callers/UI can explain *why* a module is or is not available.
 */
final readonly class EntitlementResolution
{
    public function __construct(
        public bool $enabled,
        public ?EntitlementSource $source,
        public array $settings,
        public ?FirmEntitlement $entitlement,
    ) {
    }

    public static function notEntitled(): self
    {
        return new self(enabled: false, source: null, settings: [], entitlement: null);
    }

    public static function fromEntitlement(FirmEntitlement $entitlement): self
    {
        return new self(
            enabled: $entitlement->enabled,
            source: $entitlement->source,
            settings: $entitlement->settings_json ?? [],
            entitlement: $entitlement,
        );
    }
}
