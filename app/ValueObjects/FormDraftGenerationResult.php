<?php

namespace App\ValueObjects;

class FormDraftGenerationResult
{
    public function __construct(
        public readonly int $formDraftId,
        public readonly int $valuesGenerated,
        public readonly int $missingRequiredCount,
        public readonly bool $usedSampleMapping,
    ) {
    }
}
