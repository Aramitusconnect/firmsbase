<?php

namespace App\Enums;

/**
 * NotificationTemplateStatus — notification_templates.status. No
 * exact value list given by the PDF — recommendation.
 */
enum NotificationTemplateStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
