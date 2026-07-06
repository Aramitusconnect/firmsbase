<?php

namespace App\Enums;

enum DataProcessingRecordStatus: string
{
    case Active = 'active';
    case Retired = 'retired';
}
