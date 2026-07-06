<?php

namespace App\Enums;

/**
 * LicenseValidationResult — license_validation_events.result. Grace is
 * a distinct, valid-for-now outcome (project rule: an expired license
 * enters existing grace behavior, never an instant hard block).
 */
enum LicenseValidationResult: string
{
    case Valid = 'valid';
    case Grace = 'grace';
    case Invalid = 'invalid';
}
