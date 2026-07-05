<?php

namespace App\Enums;

enum DocumentTemplateCategory: string
{
    case EngagementLetter = 'engagement_letter';
    case CoverLetter = 'cover_letter';
    case StatusUpdateLetter = 'status_update_letter';
    case Miscellaneous = 'miscellaneous';
}
