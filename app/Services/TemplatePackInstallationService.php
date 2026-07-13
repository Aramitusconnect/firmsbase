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
 *
 * Section 39A-3L, Checkpoint 6 — installed_template_packs is now FORCE
 * RLS. All three public methods below wrap their ENTIRE body (never
 * just an argument or a sub-query) in a single
 * TenantContextService::runWithFirmContext() call each, per this
 * batch's established convention (e.g. ConflictCheckService). This
 * matters most for markUpgradeAvailable()/disable(): before this fix,
 * their tap($model)->update([...]) calls would, once FORCE landed
 * with no active context, silently no-op — Eloquent's update() always
 * returns true regardless of actual affected-row count, so Postgres
 * quietly rejecting every row via the RLS policy's USING/WITH CHECK
 * clause produced no error at all, only an in-memory model that
 * looked updated while the real row was untouched. install()'s
 * withoutTenantScope() pre-check SELECT is deliberately kept inside
 * the same wrap (not in conflict with it) — see this method's own
 * inline comment.
 */
class TemplatePackInstallationService
{
    public function install(Firm $firm, TemplatePackVersion $version): InstalledTemplatePack
    {
        $tenantContext = new TenantContextService();

        return $tenantContext->runWithFirmContext($firm, function () use ($firm, $version) {
            // withoutTenantScope() is deliberately paired with the
            // whole-method runWithFirmContext() wrap above (matches the
            // established ConflictCheckService precedent) — it only
            // bypasses the Eloquent-level global scope so this
            // firm-scoped lookup can find its own existing row; the
            // database-level RLS policy is still enforced normally by
            // the active firm context.
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
        });
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
        $tenantContext = new TenantContextService();

        return $tenantContext->runWithFirmContext(
            $installed->firm_id,
            fn () => tap($installed)->update(['status' => InstalledTemplatePackStatus::UpgradeAvailable])
        );
    }

    public function disable(InstalledTemplatePack $installed): InstalledTemplatePack
    {
        $tenantContext = new TenantContextService();

        return $tenantContext->runWithFirmContext(
            $installed->firm_id,
            fn () => tap($installed)->update([
                'status' => InstalledTemplatePackStatus::Disabled,
                'disabled_at' => now(),
            ])
        );
    }
}
