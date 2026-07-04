<?php

namespace App\Enums;

/**
 * RecordStatus — generic lifecycle status used by organizations (and
 * any other master-data entity that needs simple active/inactive
 * tracking without a bespoke enum of its own).
 */
enum RecordStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';
}
