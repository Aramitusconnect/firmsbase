<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * ExternalMappingConflictException — thrown by
 * IntegrationExternalMappingService::recordMapping() when
 * `firstOrCreate()`'s own internal catch already resolved the FIRST
 * partial unique index (integration_external_mappings_external_unique
 * — same external object, fine, return existing) but the SECOND
 * partial unique index (integration_external_mappings_local_unique)
 * then rejects the insert: this exact local record is already mapped
 * to a DIFFERENT external object for this connection
 * (agent-6c-idempotency-concurrency.md §8). A genuine data-integrity
 * conflict, not an ordinary duplicate — must never be silently
 * swallowed the way createOrFirst()'s internal catch swallows the
 * first-constraint case.
 */
final class ExternalMappingConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $localType,
        public readonly int|string $localId,
        public readonly string $externalId,
    ) {
        parent::__construct(
            "Local record {$localType}#{$localId} is already mapped to a different external_id for this ".
            "connection; cannot also map it to external_id={$externalId}."
        );
    }
}
