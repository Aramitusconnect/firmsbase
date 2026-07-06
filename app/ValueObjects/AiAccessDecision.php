<?php

namespace App\ValueObjects;

/**
 * AiAccessDecision — mirrors WebhookAccessDecision's exact shape.
 * Returned by AiEntitlementPolicyService/AiModeResolutionService for
 * every gate check, always carrying a reason so a denial is
 * explainable/auditable, never a bare boolean.
 */
final readonly class AiAccessDecision
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
