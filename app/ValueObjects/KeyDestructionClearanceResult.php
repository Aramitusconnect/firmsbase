<?php

namespace App\ValueObjects;

/**
 * KeyDestructionClearanceResult — the full clearance chain a
 * KeyDestructionRequest must satisfy before it may even be submitted
 * for two-person approval: completed offboarding export, retention
 * clearance, and legal-hold clearance.
 */
final readonly class KeyDestructionClearanceResult
{
    public function __construct(
        public bool $exportCleared,
        public bool $retentionCleared,
        public bool $legalHoldCleared,
        public ?string $reason = null,
    ) {
    }

    public function isClear(): bool
    {
        return $this->exportCleared && $this->retentionCleared && $this->legalHoldCleared;
    }
}
