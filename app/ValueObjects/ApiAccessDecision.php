<?php

namespace App\ValueObjects;

/**
 * ApiAccessDecision — returned by ApiAccessPolicyService for every
 * scope/entitlement/rate-limit check. Always carries a reason so a
 * denial is explainable/auditable, never a bare boolean.
 */
final readonly class ApiAccessDecision
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
