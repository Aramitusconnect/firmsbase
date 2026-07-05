<?php

namespace App\ValueObjects;

/**
 * ExportGovernanceDecision — returned by
 * ExportGovernancePolicyService::evaluate(). Wraps
 * LegalDataAccessPolicyService::canExport() plus the legal-hold/
 * retention/offboarding checks this phase adds. Always carries a
 * reason so a blocked export is explainable/auditable.
 */
final readonly class ExportGovernanceDecision
{
    public function __construct(
        public bool $allowed,
        public ?string $reason = null,
    ) {
    }

    public static function allow(): self
    {
        return new self(true);
    }

    public static function block(string $reason): self
    {
        return new self(false, $reason);
    }
}
