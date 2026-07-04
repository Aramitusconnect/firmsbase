<?php

namespace App\Enums;

/**
 * DocumentChaseRuleStatus — document_chase_rules.status. No exact
 * value list given by the PDF — recommendation.
 */
enum DocumentChaseRuleStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';
}
