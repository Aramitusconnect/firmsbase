<?php

namespace App\Enums;

/**
 * FirmActivationStatus — firms.activation_status. Exactly three values.
 * No suspended/restricted/archived here — those belong to
 * firm_licenses.license_status (the commercial lifecycle), not to
 * firm-level activation. The transition guard requiring a non-null
 * billing_account_id (and a satisfied activation checklist, and a
 * provisioned tenant encryption key) before a firm can reach
 * `activated` is enforced by ActivationChecklistService, not by this
 * enum or by any database constraint.
 */
enum FirmActivationStatus: string
{
    case Draft = 'draft';
    case Onboarding = 'onboarding';
    case Activated = 'activated';
}
