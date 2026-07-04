<?php

namespace App\Enums;

enum SalesAssignmentStatus: string
{
    case Active = 'active';
    case Reassigned = 'reassigned';
    case Closed = 'closed';
}
