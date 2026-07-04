<?php

namespace App\Enums;

/**
 * InstalledTemplatePackStatus — installed_template_packs.status. Not
 * given exact values by the master plan (proposed/approved during
 * Phase 2 planning). UpgradeAvailable is set by
 * TemplatePackInstallationService when a newer published version of
 * an already-installed pack exists — it does not change which version
 * is pinned to any existing matter (see the "Template upgrade" edge
 * case: existing matters stay pinned; upgrade requires preview and
 * explicit apply — the "explicit apply" step and any preview UI are
 * not built in Phase 2).
 */
enum InstalledTemplatePackStatus: string
{
    case Active = 'active';
    case UpgradeAvailable = 'upgrade_available';
    case Disabled = 'disabled';
}
