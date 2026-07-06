<?php

namespace App\ValueObjects;

/**
 * DeletionClearanceResult — the same three-part clearance chain as
 * KeyDestructionClearanceResult, evaluated for a DeletionRequest.
 * Kept as a distinct value object (not reused 1:1) because deletion
 * clearance is evaluated per-record (subject_type/subject_id) rather
 * than per-firm-key.
 */
final readonly class DeletionClearanceResult
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
