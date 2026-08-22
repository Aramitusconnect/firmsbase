<?php

namespace App\ValueObjects;

/**
 * IntegrationAccessDecision — mirrors App\ValueObjects\WebhookAccessDecision's
 * exact shape (Checkpoint 9, frozen design §5). Returned by
 * App\Services\IntegrationEntitlementPolicyService for every
 * entitlement gate check, always carrying a reason so a denial is
 * explainable/auditable, never a bare boolean.
 */
final readonly class IntegrationAccessDecision
{
    public function __construct(
        public bool $allowed,
        public ?string $reason = null,
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
