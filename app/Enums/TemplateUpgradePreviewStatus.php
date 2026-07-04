<?php

namespace App\Enums;

/**
 * TemplateUpgradePreviewStatus — template_upgrade_previews.status.
 * Proposed during Phase 6 planning and approved. A preview is always
 * generated before an upgrade log entry can exist for the same
 * from/to version pair (no direct upgrade without a preview step).
 */
enum TemplateUpgradePreviewStatus: string
{
    case Generated = 'generated';
    case Reviewed = 'reviewed';
    case Applied = 'applied';
    case Discarded = 'discarded';
}
