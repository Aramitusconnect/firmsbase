<?php

namespace App\Enums;

enum AnnouncementStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';
}
