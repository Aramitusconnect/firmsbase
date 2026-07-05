<?php

namespace App\ValueObjects;

use App\Enums\GeneratedDocumentStatus;

class DocumentGenerationResult
{
    /**
     * @param array<string, ?string> $resolvedMergeValues Deterministically
     *   resolved merge-field values, in memory only — not persisted, since
     *   no real binary renderer exists yet in this phase to consume them.
     */
    public function __construct(
        public readonly int $generatedDocumentId,
        public readonly GeneratedDocumentStatus $status,
        public readonly string $simulatedStoragePath,
        public readonly bool $usedSampleContent,
        public readonly array $resolvedMergeValues = [],
    ) {
    }
}
