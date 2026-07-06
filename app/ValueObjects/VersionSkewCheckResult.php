<?php

namespace App\ValueObjects;

/**
 * VersionSkewCheckResult — returned by VersionSkewPolicyService::check().
 * Always carries a reason so a failing check is explainable/auditable,
 * matching WebhookAccessDecision/AiAccessDecision's exact reasoning.
 */
final readonly class VersionSkewCheckResult
{
    public function __construct(
        public bool $withinPolicy,
        public int $minorVersionsBehind,
        public ?string $reason = null,
    ) {
    }

    public static function pass(int $minorVersionsBehind): self
    {
        return new self(true, $minorVersionsBehind);
    }

    public static function fail(string $reason, int $minorVersionsBehind = 0): self
    {
        return new self(false, $minorVersionsBehind, $reason);
    }
}
