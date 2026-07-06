<?php

namespace App\ValueObjects;

/**
 * AccessReviewSummary — read-only rollup of an AccessReview's items,
 * used to determine whether AccessReviewService::complete() is allowed
 * (project rule: cannot complete while any item is Pending).
 */
final readonly class AccessReviewSummary
{
    public function __construct(
        public int $totalItems,
        public int $pendingCount,
        public int $retainedCount,
        public int $revokedCount,
        public int $modifiedCount,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->totalItems > 0 && $this->pendingCount === 0;
    }
}
