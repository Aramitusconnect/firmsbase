<?php

namespace App\Enums;

/**
 * SeatClass — seat_pools.seat_class, seat_allocations.seat_class,
 * firm_users.seat_class, and plan_limits' seat metrics. Exact 3 values
 * from the master plan's Phase 6 scope: attorney, staff, read_only.
 * Client portal users are never firm_users rows at all (Client is a
 * distinct model — see FirmUserRole's docblock), so they are
 * automatically uncounted by every seat computation in this enum's
 * domain without any special-case code.
 */
enum SeatClass: string
{
    case Attorney = 'attorney';
    case Staff = 'staff';
    case ReadOnly = 'read_only';
}
