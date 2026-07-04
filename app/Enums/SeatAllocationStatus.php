<?php

namespace App\Enums;

/**
 * SeatAllocationStatus — seat_allocations.status. Proposed during
 * Phase 6 planning and approved.
 */
enum SeatAllocationStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
