<?php

namespace App\ValueObjects;

/**
 * WebhookAccessDecision — mirrors ApiAccessDecision's exact shape
 * (Phase 8). Returned by WebhookEntitlementPolicyService and
 * WebhookAccessPolicyService for every gate check, always carrying a
 * reason so a denial is explainable/auditable, never a bare boolean.
 */
final readonly class WebhookAccessDecision
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
