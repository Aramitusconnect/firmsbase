<?php

namespace App\Services;

use App\Enums\StatusPageEventStatus;
use App\Models\StatusPageEvent;
use Illuminate\Support\Str;

/**
 * StatusPageService — the only place status_page_events rows are
 * created. Platform-level (no firm_id, project rule — see that
 * table's migration doc comment). No public website/status-page UI is
 * built here (project rule) — this is the process/data foundation
 * only: publish an update, add further updates to the same post via
 * correlation_id, resolve it, and optionally link it to an
 * incident_events correlation_id.
 */
class StatusPageService
{
    public function publish(
        string $eventType,
        string $componentAffected,
        string $publicMessage,
        \DateTimeInterface $startsAt,
        ?string $incidentCorrelationId = null,
    ): StatusPageEvent {
        return StatusPageEvent::create([
            'correlation_id' => (string) Str::uuid(),
            'incident_correlation_id' => $incidentCorrelationId,
            'event_type' => $eventType,
            'status' => StatusPageEventStatus::Published,
            'component_affected' => $componentAffected,
            'public_message' => $publicMessage,
            'starts_at' => $startsAt,
        ]);
    }

    public function update(string $correlationId, string $eventType, string $publicMessage): StatusPageEvent
    {
        $current = $this->currentState($correlationId);

        return StatusPageEvent::create([
            'correlation_id' => $current->correlation_id,
            'incident_correlation_id' => $current->incident_correlation_id,
            'event_type' => $eventType,
            'status' => $current->status,
            'component_affected' => $current->component_affected,
            'public_message' => $publicMessage,
            'starts_at' => $current->starts_at,
        ]);
    }

    public function resolvePublicly(string $correlationId, string $publicMessage): StatusPageEvent
    {
        $current = $this->currentState($correlationId);

        return StatusPageEvent::create([
            'correlation_id' => $current->correlation_id,
            'incident_correlation_id' => $current->incident_correlation_id,
            'event_type' => 'resolved',
            'status' => $current->status,
            'component_affected' => $current->component_affected,
            'public_message' => $publicMessage,
            'starts_at' => $current->starts_at,
            'resolved_at' => now(),
        ]);
    }

    public function unpublish(string $correlationId): StatusPageEvent
    {
        $current = $this->currentState($correlationId);

        return StatusPageEvent::create([
            'correlation_id' => $current->correlation_id,
            'incident_correlation_id' => $current->incident_correlation_id,
            'event_type' => 'unpublished',
            'status' => StatusPageEventStatus::Unpublished,
            'component_affected' => $current->component_affected,
            'public_message' => $current->public_message,
            'starts_at' => $current->starts_at,
        ]);
    }

    public function currentState(string $correlationId): StatusPageEvent
    {
        return StatusPageEvent::query()
            ->where('correlation_id', $correlationId)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * @return \Illuminate\Support\Collection<int, StatusPageEvent>
     */
    public function timeline(string $correlationId)
    {
        return StatusPageEvent::query()
            ->where('correlation_id', $correlationId)
            ->oldest('id')
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, StatusPageEvent>
     */
    public function forIncident(string $incidentCorrelationId)
    {
        return StatusPageEvent::query()
            ->where('incident_correlation_id', $incidentCorrelationId)
            ->oldest('id')
            ->get();
    }
}
