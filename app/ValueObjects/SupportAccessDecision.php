<?php

namespace App\ValueObjects;

/**
 * SupportAccessDecision — returned by SupportAccessPolicyService when
 * deciding whether a given support access request/session may proceed.
 * Carries the reason for a denial so callers can log/audit it, never
 * just a bare boolean.
 */
final readonly class SupportAccessDecision
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
