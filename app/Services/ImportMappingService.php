<?php

namespace App\Services;

use App\Enums\ImportAuditEventType;
use App\Models\ImportBatch;
use App\Models\ImportMapping;

/**
 * ImportMappingService — the only writer of import_mappings.
 */
class ImportMappingService
{
    public function __construct(
        private readonly ImportAuditService $auditService,
    ) {
    }

    /**
     * @param array<int, array{source_field: string, target_field: string, transform_rule?: string|null, is_required?: bool}> $mappings
     * @return ImportMapping[]
     */
    public function saveMappings(ImportBatch $batch, array $mappings): array
    {
        $saved = [];

        foreach ($mappings as $mapping) {
            $saved[] = ImportMapping::query()->updateOrCreate(
                ['import_batch_id' => $batch->id, 'source_field' => $mapping['source_field']],
                [
                    'target_field' => $mapping['target_field'],
                    'transform_rule' => $mapping['transform_rule'] ?? null,
                    'is_required' => $mapping['is_required'] ?? false,
                ],
            );
        }

        $this->auditService->record($batch, ImportAuditEventType::MappingSaved, metadata: ['count' => count($saved)]);

        return $saved;
    }

    /**
     * Applies saved mappings to a row's raw_data, producing mapped_data.
     * No transform_rule execution beyond a plain rename is implemented
     * in this phase (foundation only) — transform_rule is stored for a
     * future phase to interpret.
     */
    public function applyMappingsToRawData(ImportBatch $batch, array $rawData): array
    {
        $mapped = [];

        foreach ($batch->mappings as $mapping) {
            if (array_key_exists($mapping->source_field, $rawData)) {
                $mapped[$mapping->target_field] = $rawData[$mapping->source_field];
            }
        }

        return $mapped;
    }
}
