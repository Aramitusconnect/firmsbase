<?php

namespace App\ValueObjects;

/**
 * LegalHoldCheckResult — output of LegalHoldService::checkHold(). An
 * active hold blocks deletion and key destruction only — never export
 * or archive (Master Plan edge-case table, page 50).
 */
final readonly class LegalHoldCheckResult
{
    public function __construct(
        public bool $blocked,
        /** @var array<int, int> legal_holds.id values currently active for this scope */
        public array $activeHoldIds = [],
    ) {
    }

    public static function notBlocked(): self
    {
        return new self(false);
    }

    public static function blockedBy(array $activeHoldIds): self
    {
        return new self(true, $activeHoldIds);
    }
}
