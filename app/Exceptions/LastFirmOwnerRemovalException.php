<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * LastFirmOwnerRemovalException — thrown by `FirmUserInvitationService::
 * suspend()`/`remove()` when the target `FirmUser` is the firm's LAST
 * remaining Active FirmOwner. A hard, service-level guard (never merely
 * a UI-level disable) per Firm Feature Manifest §12's explicit
 * requirement: a firm must never be left with zero active owners able
 * to manage it, since that would permanently lock the firm out of its
 * own team-management surface (only a FirmOwner may invite/suspend/
 * remove — see `FirmMembershipAccessPolicyService::canManageMembers()`).
 */
class LastFirmOwnerRemovalException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Cannot suspend or remove the last remaining active owner of this firm. Promote another member to Firm Owner first.'
        );
    }
}
