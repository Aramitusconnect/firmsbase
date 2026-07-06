<?php

namespace App\ValueObjects;

/**
 * RetentionClearanceResult — output of RetentionPolicyService's
 * clearance check. `cleared` is false whenever no applicable policy
 * exists (project rule: "if no policy exists, clearance is false, not
 * unrestricted") or whenever the resolved policy is permanent.
 */
final readonly class RetentionClearanceResult
{
    public function __construct(
        public bool $cleared,
        public ?string $reason = null,
    ) {
    }

    public static function cleared(): self
    {
        return new self(true);
    }

    public static function notCleared(string $reason): self
    {
        return new self(false, $reason);
    }
}
