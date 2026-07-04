<?php

namespace App\ValueObjects;

/**
 * PlatformStaffAccessDecision — returned by PlatformStaffAccessPolicyService
 * for a given (PlatformAdmin, resource-category) check, e.g. "can this
 * sales rep read client data" or "can this billing admin read document
 * contents." Always carries a reason so a denial is auditable and
 * explainable, never a bare boolean.
 */
final readonly class PlatformStaffAccessDecision
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

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
