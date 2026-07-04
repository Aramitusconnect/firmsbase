<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\InstalledTemplatePack;
use App\Models\TemplatePackVersion;

/**
 * TemplatePackCommercialService — wraps the EXISTING
 * TemplatePackInstallationService (Phase 2) WITHOUT modifying it, adding
 * an entitlement gate in front of install(): a firm may only install a
 * template pack version if its resolved entitlement for the
 * 'practice_area_templates' module is enabled. This is the Phase 6
 * "license/entitlement gating" for template management; the actual
 * install/upgrade-flag/disable mechanics are entirely delegated.
 */
class TemplatePackCommercialService
{
    private const MODULE_CODE = 'practice_area_templates';

    public function __construct(
        private EntitlementService $entitlementService,
        private TemplatePackInstallationService $installationService,
    ) {
    }

    /**
     * @throws \RuntimeException if the firm is not entitled to install template packs.
     */
    public function installIfEntitled(Firm $firm, TemplatePackVersion $version): InstalledTemplatePack
    {
        if (! $this->entitlementService->isEnabled($firm->id, self::MODULE_CODE)) {
            throw new \RuntimeException(
                "Firm #{$firm->id} is not entitled to the '".self::MODULE_CODE."' module — template pack install blocked."
            );
        }

        return $this->installationService->install($firm, $version);
    }
}
