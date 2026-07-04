<?php

namespace App\Enums;

/**
 * SeatPoolStatus — seat_pools.status. Proposed during Phase 6 planning
 * and approved.
 */
enum SeatPoolStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';
}
