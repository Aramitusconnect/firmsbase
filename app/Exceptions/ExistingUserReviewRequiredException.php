<?php

namespace App\Exceptions;

/**
 * ExistingUserReviewRequiredException — thrown by
 * FirmProvisioningService::provision() when the submitted owner email
 * already belongs to a `users` row and the caller did not explicitly
 * set FirmProvisioningInput::$reuseExistingUser. An existing identity is
 * never silently attached to a new firm — the operator must make an
 * explicit, audited decision (reuse or pick a different email) rather
 * than have this service guess.
 */
class ExistingUserReviewRequiredException extends \RuntimeException
{
    public function __construct(public readonly int $existingUserId)
    {
        parent::__construct(
            "The email address already belongs to an existing user (id {$existingUserId}). ".
            'Explicit review is required before reusing an existing identity as a firm owner.'
        );
    }
}
