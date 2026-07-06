<?php

namespace App\Enums;

/**
 * LicenseValidationEventType — license_validation_events.event_type.
 * Every LicenseFileValidationService::validate() call writes exactly
 * one row using one of these values, regardless of outcome.
 */
enum LicenseValidationEventType: string
{
    case Validated = 'validated';
    case Expired = 'expired';
    case EnteredGrace = 'entered_grace';
    case GraceExpired = 'grace_expired';
    case SignatureInvalid = 'signature_invalid';
    case RevokedCheck = 'revoked_check';
}
