<?php

namespace App\Services;

use App\Enums\InstalledTemplatePackStatus;
use App\Models\Firm;
use App\Models\InstalledTemplatePack;
use App\Models\TemplatePackVersion;

/**
 * TemplatePackInstallationService — installs/upgrades a template pack
 * version for a firm. Upgrading updates the firm's
 * InstalledTemplatePack row in place; it never touches
 * Matter::pinned_template_pack_version_id on matters that already
 * exist (see the migration/model comments — pinning is permanent at
 * matter-creation time).
 */
class TemplatePackInstallationService
{
    public function install(Firm $firm, TemplatePackVersion $version): InstalledTemplatePack
    {
        $existing = InstalledTemplatePack::withoutTenantScope()
            ->where('firm_id', $firm->id)
            ->where('template_pack_id', $version->template_pack_id)
            ->first();

        if ($existing) {
            return tap($existing)->update([
                'template_pack_version_id' => $version->id,
                'status' => InstalledTemplatePackStatus::Active,
                'installed_at' => now(),
                'disabled_at' => null,
            ]);
        }

        return InstalledTemplatePack::create([
            'firm_id' => $firm->id,
            'template_pack_id' => $version->template_pack_id,
            'template_pack_version_id' => $version->id,
            'status' => InstalledTemplatePackStatus::Active,
            'installed_at' => now(),
        ]);
    }

    /**
     * Flags that a newer published version exists, WITHOUT changing
     * which version is installed/active. Applying the upgrade is a
     * separate, explicit call to install() with the newer version —
     * "Template upgrade" edge case: upgrade requires preview and
     * explicit apply rules, never an automatic switch.
     */
    public function markUpgradeAvailable(InstalledTemplatePack $installed): InstalledTemplatePack
    {
        return tap($installed)->update(['status' => InstalledTemplatePackStatus::UpgradeAvailable]);
    }

    public function disable(InstalledTemplatePack $installed): InstalledTemplatePack
    {
        return tap($installed)->update([
            'status' => InstalledTemplatePackStatus::Disabled,
            'disabled_at' => now(),
        ]);
    }
}
