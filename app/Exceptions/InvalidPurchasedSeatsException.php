<?php

namespace App\Exceptions;

/**
 * InvalidPurchasedSeatsException — thrown by
 * FirmProvisioningService::provisionLocalRecords() when a `planId` is
 * supplied but `purchasedSeats` is missing or not a positive integer.
 * A plan-less firm has no `FirmLicense` at all (unchanged prior
 * behavior — see FirmProvisioningInput's own docblock), so this is
 * never thrown when `planId` is null. Mirrors
 * InactivePlanSelectedException's own "re-validate at the moment the
 * transaction actually runs, not just at the wizard's earlier
 * client-side validation" discipline — a plan-selected submission
 * missing this field must never silently create a `FirmLicense` with
 * no seat quantity (that is exactly the "zero seats" bug this whole
 * class of change exists to close).
 */
class InvalidPurchasedSeatsException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('A purchased seat quantity (a positive whole number) is required when assigning a plan to a firm.');
    }
}
