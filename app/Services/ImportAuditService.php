<?php

namespace App\Services;

use App\Enums\ImportAuditEventType;
use App\Models\ImportAuditEvent;
use App\Models\ImportBatch;

/**
 * ImportAuditService — the only writer of import_audit_events (project
 * rule 6). Every stage of the import lifecycle records an event here.
 */
class ImportAuditService
{
    public function record(
        ImportBatch $batch,
        ImportAuditEventType $eventType,
        ?string $actorType = null,
        ?int $actorId = null,
        array $metadata = [],
    ): ImportAuditEvent {
        return ImportAuditEvent::create([
            'import_batch_id' => $batch->id,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
