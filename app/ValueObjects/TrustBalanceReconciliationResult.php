<?php

namespace App\ValueObjects;

/**
 * TrustBalanceReconciliationResult — returned by
 * TrustBalanceService::reconcileCacheAgainstLedger(). Carries both
 * figures and the difference explicitly so a mismatch is always a
 * visible, checkable fact rather than an implicit side effect.
 */
final readonly class TrustBalanceReconciliationResult
{
    public function __construct(
        public bool $matches,
        public int $cachedBalanceCents,
        public int $computedBalanceCents,
        public int $differenceCents,
    ) {
    }
}
