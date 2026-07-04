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
 */
class TemplateUpgradePreviewService
{
    public function preview(
        InstalledTemplatePack $installed,
        TemplatePackVersion $toVersion,
        ?User $actor = null,
    ): TemplateUpgradePreview {
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
    }

    public function markReviewed(TemplateUpgradePreview $preview): TemplateUpgradePreview
    {
        return tap($preview)->update(['status' => TemplateUpgradePreviewStatus::Reviewed])->fresh();
    }

    public function discard(TemplateUpgradePreview $preview): TemplateUpgradePreview
    {
        return tap($preview)->update(['status' => TemplateUpgradePreviewStatus::Discarded])->fresh();
    }
}
