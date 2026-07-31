<?php

namespace App\Exceptions;

/**
 * FirmProvisioningRequestChangedException — thrown by
 * FirmProvisioningService::provision() when an idempotency key that
 * already resolved to a COMPLETED request is resubmitted with a
 * DIFFERENT payload hash. A genuine retry of the identical wizard
 * submission always carries the identical hash; a mismatch means the
 * caller is reusing an old key for a new, different request — refused
 * outright rather than silently provisioning a second firm under a key
 * that already means something else.
 */
class FirmProvisioningRequestChangedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This idempotency key was already used for a different provisioning request.');
    }
}
