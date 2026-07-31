<?php

namespace App\ValueObjects;

use App\Enums\FirmProvisioningStatus;
use App\Models\Firm;
use App\Models\FirmProvisioningRequest;
use App\Models\User;

/**
 * FirmProvisioningResult — what FirmProvisioningService::provision()
 * returns. Deliberately carries no password, token, or key material —
 * only durable-row references and the outcome status.
 */
final readonly class FirmProvisioningResult
{
    public function __construct(
        public FirmProvisioningRequest $request,
        public Firm $firm,
        public User $owner,
        public FirmProvisioningStatus $status,
        public bool $resumedFromExistingRequest,
    ) {}
}
