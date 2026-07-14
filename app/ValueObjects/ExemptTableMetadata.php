<?php

namespace App\ValueObjects;

/**
 * ExemptTableMetadata — the documented reason, expected readers, and
 * authorized writers for one table in
 * RowLevelSecurityCoverageMappingService::EXEMPT_TABLES. Every entry
 * in EXEMPT_TABLES (including the two Wave 1A additions, module_catalog
 * and readiness_scorecard_components) has exactly one of these.
 */
final readonly class ExemptTableMetadata
{
    /**
     * @param  array<int, string>  $expectedReaders
     * @param  array<int, string>  $authorizedWriters
     */
    public function __construct(
        public string $table,
        public string $reason,
        public array $expectedReaders,
        public array $authorizedWriters,
    ) {}
}
