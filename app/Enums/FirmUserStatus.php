<?php

namespace App\Enums;

/**
 * FirmUserStatus — firm_users.status. Membership lifecycle, separate
 * from the underlying users.is_active flag (a user can be globally
 * active but pending/removed from one specific firm).
 */
enum FirmUserStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';
    case Removed = 'removed';
}
