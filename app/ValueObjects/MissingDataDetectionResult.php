<?php

namespace App\ValueObjects;

class MissingDataDetectionResult
{
    /**
     * @param array<int, string> $missingFieldCodes
     */
    public function __construct(
        public readonly int $formDraftId,
        public readonly array $missingFieldCodes,
    ) {
    }

    public function isComplete(): bool
    {
        return empty($this->missingFieldCodes);
    }
}
