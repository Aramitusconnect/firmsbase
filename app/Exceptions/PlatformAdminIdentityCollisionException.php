<?php

namespace App\Exceptions;

/**
 * PlatformAdminIdentityCollisionException — thrown by
 * FirmProvisioningService::provision() when the submitted owner email
 * matches an existing `platform_admins` row. A platform staff identity
 * must never also become a firm-tenant login — mixing the two blurs the
 * exact boundary FirmPolicy's own docblock treats as load-bearing
 * (PlatformAdmin is the only actor type authorized against
 * Firm/FirmUser instances; a platform admin who could also log in AS a
 * firm owner would need every firm-tenant authorization path to somehow
 * also account for platform-level privilege, which this codebase never
 * does anywhere else).
 */
class PlatformAdminIdentityCollisionException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This email address belongs to an existing Platform Admin and cannot be used as a firm owner.');
    }
}
