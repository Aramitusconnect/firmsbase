<?php

namespace App\Services;

use App\Enums\TemplateUpgradePreviewStatus;
use App\Models\InstalledTemplatePack;
use App\Models\TemplatePackVersion;
use App\Models\TemplateUpgradePreview;
use App\Models\User;

/**
 * TemplateUpgradePreviewService — the only place
 * template_upgrade_previews rows are created. Generates a diff summary
 * between the firm's currently-installed version and a candidate newer
 * version WITHOUT touching InstalledTemplatePack itself — applying the
 * upgrade is a separate, explicit TemplateUpgradeLogService call.
 *
 * Section 39A-3L, Checkpoint 8 — template_upgrade_previews is now
 * FORCE RLS-enabled. All three methods below previously had zero
 * tenant-context wrapping (confirmed by three independent Phase A
 * audits) and none of them call another already-self-wrapping method,
 * so each is now wrapped whole-method in
 * TenantContextService::runWithFirmContext() — no nesting risk.
 * markReviewed()/discard() wrap the ENTIRE tap(...)->update(...)->fresh()
 * chain, not just the update(): fresh() is itself a SELECT that RLS
 * also blocks once context clears, and letting it run outside the
 * wrap would silently return null (a TypeError on the return type)
 * rather than cleanly succeeding.
 */
class TemplateUpgradePreviewService
{
    public function preview(
        InstalledTemplatePack $installed,
        TemplatePackVersion $toVersion,
        ?User $actor = null,
    ): TemplateUpgradePreview {
        $tenantContext = new TenantContextService();

        return $tenantContext->runWithFirmContext($installed->firm_id, function () use ($installed, $toVersion, $actor) {
            $fromVersion = $installed->templatePackVersion;

            return TemplateUpgradePreview::create([
                'firm_id' => $installed->firm_id,
                'installed_template_pack_id' => $installed->id,
                'from_version_id' => $fromVersion->id,
                'to_version_id' => $toVersion->id,
                'status' => TemplateUpgradePreviewStatus::Generated,
                'diff_summary_json' => [
                    'from_version' => $fromVersion->version,
                    'to_version' => $toVersion->version,
                    'release_notes' => $toVersion->release_notes,
                ],
                'previewed_at' => now(),
                'previewed_by' => $actor?->id,
            ]);
        });
    }

    public function markReviewed(TemplateUpgradePreview $preview): TemplateUpgradePreview
    {
        $tenantContext = new TenantContextService();

        return $tenantContext->runWithFirmContext(
            $preview->firm_id,
            fn () => tap($preview)->update(['status' => TemplateUpgradePreviewStatus::Reviewed])->fresh()
        );
    }

    public function discard(TemplateUpgradePreview $preview): TemplateUpgradePreview
    {
        $tenantContext = new TenantContextService();

        return $tenantContext->runWithFirmContext(
            $preview->firm_id,
            fn () => tap($preview)->update(['status' => TemplateUpgradePreviewStatus::Discarded])->fresh()
        );
    }
}
