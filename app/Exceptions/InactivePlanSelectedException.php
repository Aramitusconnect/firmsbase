<?php

namespace App\Exceptions;

/**
 * InactivePlanSelectedException — FIRMSVAULT STAGING ADMIN
 * STABILIZATION. Thrown by FirmProvisioningService::provisionLocalRecords()
 * when the resolved Plan is not App\Enums\PlanStatus::Active at the
 * moment the transaction actually runs. The wizard's own Select only
 * offers Active plans at search time (ProvisionFirmAction's own
 * `plan_id` Select), but nothing previously re-checked that status at
 * submission time — a plan archived or left in Draft between page load
 * and submit could otherwise still be silently assigned to a new
 * FirmLicense. Thrown from inside the service's own outer
 * DB::transaction(), so the whole provisioning attempt rolls back
 * atomically — never a partial Firm with no usable plan.
 */
class InactivePlanSelectedException extends \RuntimeException
{
    public function __construct(string $planName, string $status)
    {
        parent::__construct("The selected plan \"{$planName}\" is no longer active (status: {$status}) and cannot be assigned to a new firm.");
    }
}
