<?php

namespace App\Enums;

/**
 * TemplatePackStatus — used by both template_packs.status and
 * template_pack_versions.status. Not given exact values by the master
 * plan; matches the Admin Control Catalog's "Create template packs;
 * version packs; publish/unpublish" language (proposed/approved during
 * Phase 2 planning).
 */
enum TemplatePackStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Deprecated = 'deprecated';
}
