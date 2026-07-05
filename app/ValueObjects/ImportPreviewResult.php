<?php

namespace App\ValueObjects;

/**
 * ImportPreviewResult — returned by ImportPreviewService::preview().
 * Pure read/summary object — generating a preview NEVER creates a
 * production record for any entity type (project rule).
 */
final readonly class ImportPreviewResult
{
    public function __construct(
        public int $importBatchId,
        public int $totalRows,
        public int $validRows,
        public int $invalidRows,
        public int $duplicateRows,
        public array $sampleMappedRows,
    ) {
    }
}
