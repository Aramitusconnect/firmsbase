<?php

namespace App\ValueObjects;

use App\Enums\CommissionEventStatus;

/**
 * CommissionEligibilityResult — returned by CommissionEligibilityService.
 * Carries the decided CommissionEventStatus plus the specific
 * disqualifying platform_billing_events event_types (if any) that
 * caused a non-payable result, so callers/tests can assert on the
 * exact reason rather than just a boolean.
 */
final readonly class CommissionEligibilityResult
{
    /**
     * @param string[] $disqualifyingReasons
     */
    public function __construct(
        public bool $payable,
        public CommissionEventStatus $status,
        public array $disqualifyingReasons,
    ) {
    }

    public static function payable(): self
    {
        return new self(true, CommissionEventStatus::Payable, []);
    }

    public static function blocked(array $reasons): self
    {
        return new self(false, CommissionEventStatus::Blocked, $reasons);
    }
}
