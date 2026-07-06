<?php

namespace App\ValueObjects;

use App\Enums\LicenseValidationEventType;
use App\Enums\LicenseValidationResult;

/**
 * LicenseValidationOutcome — deliberately NOT named
 * LicenseValidationResult (that name is already the App\Enums case
 * set) to avoid same-name-different-namespace confusion when both are
 * used together, which every real call site does. Returned by
 * LicenseFileValidationService::validate() — always carries the
 * specific event type written to license_validation_events alongside
 * the coarser result, plus a human reason for Grace/Invalid outcomes.
 */
final readonly class LicenseValidationOutcome
{
    public function __construct(
        public LicenseValidationResult $result,
        public LicenseValidationEventType $eventType,
        public ?string $reason = null,
    ) {
    }

    public function isValid(): bool
    {
        return $this->result === LicenseValidationResult::Valid;
    }

    public function isGrace(): bool
    {
        return $this->result === LicenseValidationResult::Grace;
    }
}
