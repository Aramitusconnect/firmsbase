<?php

namespace App\Enums;

enum ReleaseNoteStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
