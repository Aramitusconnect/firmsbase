<?php

namespace App\Enums;

/**
 * TemplateUpgradeLogStatus — template_upgrade_logs.status. Proposed
 * during Phase 6 planning and approved. RolledBack rows always
 * reference the log entry they undo via rollback_of_id — rollback
 * NEVER deletes or mutates the original Applied row (append-only,
 * matching project rule "no hard deletes for ... audit ... records").
 */
enum TemplateUpgradeLogStatus: string
{
    case Applied = 'applied';
    case RolledBack = 'rolled_back';
    case Failed = 'failed';
}
