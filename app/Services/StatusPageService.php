<?php

namespace App\Services;

use App\Enums\StatusPageEventStatus;
use App\Models\PlatformAdmin;
use App\Models\StatusPageEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * StatusPageService — the only place status_page_events rows are
 * created. Platform-level (no firm_id, project rule — see that
 * table's migration doc comment). No public website/status-page UI is
 * built here (project rule) — this is the process/data foundation
 * only: publish an update, add further updates to the same post via
 * correlation_id, resolve it, and optionally link it to an
 * incident_events correlation_id.
 *
 * Phase 4 (FirmsVault Platform Admin Control Center, "Operations")
 * addition: every method below now accepts an optional
 * `?PlatformAdmin $actor = null`. Unlike IncidentService, this table
 * carries no actor column at all — this is a genuine "zero actor
 * param" gap, identical in shape to PlanService::activate()/archive():
 * a purely additive parameter, recording a
 * PlatformAdminAuditEventRecorder::recordPlatformEvent() row (the
 * firm-less variant — status_page_events has no firm_id at all) only
 * when $actor is supplied. Byte-for-byte unchanged behavior when
 * $actor is null (every pre-existing caller today).
 */
class StatusPageService
{
    private const AUDIT_CATEGORY = 'operations_status_page';

    public function __construct(
        private readonly PlatformAdminAuditEventRecorder $auditRecorder = new PlatformAdminAuditEventRecorder,
    ) {}

    public function publish(
        string $eventType,
        string $componentAffected,
        string $publicMessage,
        \DateTimeInterface $startsAt,
        ?string $incidentCorrelationId = null,
        ?PlatformAdmin $actor = null,
    ): StatusPageEvent {
        $event = StatusPageEvent::create([
            'correlation_id' => (string) Str::uuid(),
            'incident_correlation_id' => $incidentCorrelationId,
            'event_type' => $eventType,
            'status' => StatusPageEventStatus::Published,
            'component_affected' => $componentAffected,
            'public_message' => $publicMessage,
            'starts_at' => $startsAt,
        ]);

        $this->recordAudit($actor, 'status_page_event_published', $event);

        return $event;
    }

    public function update(string $correlationId, string $eventType, string $publicMessage, ?PlatformAdmin $actor = null): StatusPageEvent
    {
        $current = $this->currentState($correlationId);

        $event = StatusPageEvent::create([
            'correlation_id' => $current->correlation_id,
            'incident_correlation_id' => $current->incident_correlation_id,
            'event_type' => $eventType,
            'status' => $current->status,
            'component_affected' => $current->component_affected,
            'public_message' => $publicMessage,
            'starts_at' => $current->starts_at,
        ]);

        $this->recordAudit($actor, 'status_page_event_updated', $event);

        return $event;
    }

    public function resolvePublicly(string $correlationId, string $publicMessage, ?PlatformAdmin $actor = null): StatusPageEvent
    {
        $current = $this->currentState($correlationId);

        $event = StatusPageEvent::create([
            'correlation_id' => $current->correlation_id,
            'incident_correlation_id' => $current->incident_correlation_id,
            'event_type' => 'resolved',
            'status' => $current->status,
            'component_affected' => $current->component_affected,
            'public_message' => $publicMessage,
            'starts_at' => $current->starts_at,
            'resolved_at' => now(),
        ]);

        $this->recordAudit($actor, 'status_page_event_resolved_publicly', $event);

        return $event;
    }

    public function unpublish(string $correlationId, ?PlatformAdmin $actor = null): StatusPageEvent
    {
        $current = $this->currentState($correlationId);

        $event = StatusPageEvent::create([
            'correlation_id' => $current->correlation_id,
            'incident_correlation_id' => $current->incident_correlation_id,
            'event_type' => 'unpublished',
            'status' => StatusPageEventStatus::Unpublished,
            'component_affected' => $current->component_affected,
            'public_message' => $current->public_message,
            'starts_at' => $current->starts_at,
        ]);

        $this->recordAudit($actor, 'status_page_event_unpublished', $event);

        return $event;
    }

    public function currentState(string $correlationId): StatusPageEvent
    {
        return StatusPageEvent::query()
            ->where('correlation_id', $correlationId)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * @return Collection<int, StatusPageEvent>
     */
    public function timeline(string $correlationId)
    {
        return StatusPageEvent::query()
            ->where('correlation_id', $correlationId)
            ->oldest('id')
            ->get();
    }

    /**
     * @return Collection<int, StatusPageEvent>
     */
    public function forIncident(string $incidentCorrelationId)
    {
        return StatusPageEvent::query()
            ->where('incident_correlation_id', $incidentCorrelationId)
            ->oldest('id')
            ->get();
    }

    private function recordAudit(?PlatformAdmin $actor, string $eventType, StatusPageEvent $event): void
    {
        if ($actor === null) {
            return;
        }

        $this->auditRecorder->recordPlatformEvent($actor, $eventType, self::AUDIT_CATEGORY, [
            'status_page_event_id' => $event->id,
            'correlation_id' => $event->correlation_id,
        ]);
    }
}
