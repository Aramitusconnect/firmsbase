<?php

namespace App\ValueObjects;

/**
 * AiBudgetCheckResult — returned by AiBudgetEnforcementService. Always
 * carries both the firm-level and organization-level verdicts
 * separately, since a request can be within one budget and outside the
 * other (organization-level budgets use the existing UsageRollup
 * pattern, project rule/Master Plan §22 acceptance criteria).
 */
final readonly class AiBudgetCheckResult
{
    public function __construct(
        public bool $withinFirmTokenLimit,
        public bool $withinFirmBudget,
        public bool $withinOrganizationBudget,
        public ?string $reason = null,
    ) {
    }

    public function allowed(): bool
    {
        return $this->withinFirmTokenLimit && $this->withinFirmBudget && $this->withinOrganizationBudget;
    }

    public static function allow(): self
    {
        return new self(true, true, true);
    }

    public static function deny(string $reason, bool $withinFirmTokenLimit = true, bool $withinFirmBudget = true, bool $withinOrganizationBudget = true): self
    {
        return new self($withinFirmTokenLimit, $withinFirmBudget, $withinOrganizationBudget, $reason);
    }
}
