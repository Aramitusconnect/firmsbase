<?php

namespace App\ValueObjects;

/**
 * OffboardingReadinessResult — the three-part clearance state an
 * OffboardingRequest must satisfy before it can reach ReadyForDeletion:
 * export completed/verified, retention cleared, no active legal hold.
 */
final readonly class OffboardingReadinessResult
{
    public function __construct(
        public bool $exportCompleted,
        public bool $retentionCleared,
        public bool $legalHoldCleared,
    ) {
    }

    public function isReady(): bool
    {
        return $this->exportCompleted && $this->retentionCleared && $this->legalHoldCleared;
    }
}
