<?php

namespace App\Services;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\Firm;
use App\Models\IncidentEvent;
use App\Models\User;
use App\Services\TenantContextService;
use Illuminate\Support\Str;

/**
 * IncidentService — the only place incident_events rows are created.
 * There is no separate "incidents" parent table (approved decision —
 * see incident_events' migration doc comment): correlation_id ties
 * every event for one incident together, mirroring Phase 4's
 * notification_events.correlation_id pattern. currentState() (the
 * latest row) is the internal incident dashboard's "current state"
 * view; timeline() (every row, in order) is its event-timeline view —
 * together these ARE the "internal incident dashboard data model
 * foundation" the master plan requires. No UI is built here (project
 * rule).
 */
class IncidentService
{
    public function open(
        ?Firm $firm,
        IncidentSeverity $severity,
        string $message,
        bool $customerImpact = false,
        bool $notificationNeeded = false,
        ?User $actor = null,
    ): IncidentEvent {
        $correlationId = (string) Str::uuid();

        $create = fn () => IncidentEvent::create([
            'firm_id' => $firm?->id,
            'correlation_id' => $correlationId,
            'event_type' => 'opened',
            'severity' => $severity,
            'status' => IncidentStatus::Investigating,
            'customer_impact' => $customerImpact,
            'notification_needed' => $notificationNeeded,
            'message' => $message,
            'actor_user_id' => $actor?->id,
        ]);

        $tenantContext = app(TenantContextService::class);

        return $firm
            ? $tenantContext->runWithFirmContext($firm, $create)
            : $tenantContext->runWithoutFirmContext($create);
    }

    public function updateSeverity(?Firm $firm, string $correlationId, IncidentSeverity $severity, ?User $actor = null): IncidentEvent
    {
        $tenantContext = app(TenantContextService::class);

        $body = function () use ($correlationId, $severity, $actor) {
            $current = $this->currentState($correlationId);

            return $this->appendEvent($current, 'severity_changed', ['severity' => $severity], $actor);
        };

        return $firm
            ? $tenantContext->runWithFirmContext($firm, $body)
            : $tenantContext->runWithoutFirmContext($body);
    }

    public function updateStatus(?Firm $firm, string $correlationId, IncidentStatus $status, ?User $actor = null): IncidentEvent
    {
        $tenantContext = app(TenantContextService::class);

        $body = function () use ($correlationId, $status, $actor) {
            $current = $this->currentState($correlationId);

            return $this->appendEvent($current, 'status_changed', ['status' => $status], $actor);
        };

        return $firm
            ? $tenantContext->runWithFirmContext($firm, $body)
            : $tenantContext->runWithoutFirmContext($body);
    }

    public function recordRootCause(?Firm $firm, string $correlationId, string $rootCause, ?User $actor = null): IncidentEvent
    {
        $tenantContext = app(TenantContextService::class);

        $body = function () use ($correlationId, $rootCause, $actor) {
            $current = $this->currentState($correlationId);

            return $this->appendEvent($current, 'root_cause_added', ['root_cause' => $rootCause], $actor);
        };

        return $firm
            ? $tenantContext->runWithFirmContext($firm, $body)
            : $tenantContext->runWithoutFirmContext($body);
    }

    public function flagCustomerImpact(?Firm $firm, string $correlationId, bool $customerImpact, ?User $actor = null): IncidentEvent
    {
        $tenantContext = app(TenantContextService::class);

        $body = function () use ($correlationId, $customerImpact, $actor) {
            $current = $this->currentState($correlationId);

            return $this->appendEvent($current, 'customer_impact_flagged', ['customer_impact' => $customerImpact], $actor);
        };

        return $firm
            ? $tenantContext->runWithFirmContext($firm, $body)
            : $tenantContext->runWithoutFirmContext($body);
    }

    public function flagNotificationNeeded(?Firm $firm, string $correlationId, bool $notificationNeeded, ?User $actor = null): IncidentEvent
    {
        $tenantContext = app(TenantContextService::class);

        $body = function () use ($correlationId, $notificationNeeded, $actor) {
            $current = $this->currentState($correlationId);

            return $this->appendEvent($current, 'notification_needed_flagged', ['notification_needed' => $notificationNeeded], $actor);
        };

        return $firm
            ? $tenantContext->runWithFirmContext($firm, $body)
            : $tenantContext->runWithoutFirmContext($body);
    }

    public function resolve(?Firm $firm, string $correlationId, string $resolution, ?User $actor = null): IncidentEvent
    {
        $tenantContext = app(TenantContextService::class);

        $body = function () use ($correlationId, $resolution, $actor) {
            $current = $this->currentState($correlationId);

            return $this->appendEvent($current, 'resolved', ['status' => IncidentStatus::Resolved, 'resolution' => $resolution], $actor);
        };

        return $firm
            ? $tenantContext->runWithFirmContext($firm, $body)
            : $tenantContext->runWithoutFirmContext($body);
    }

    public function currentState(string $correlationId): IncidentEvent
    {
        return IncidentEvent::query()
            ->where('correlation_id', $correlationId)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * @return \Illuminate\Support\Collection<int, IncidentEvent>
     */
    public function timeline(string $correlationId)
    {
        return IncidentEvent::query()
            ->where('correlation_id', $correlationId)
            ->oldest('id')
            ->get();
    }

    private function appendEvent(IncidentEvent $current, string $eventType, array $overrides, ?User $actor): IncidentEvent
    {
        return IncidentEvent::create([
            'firm_id' => $current->firm_id,
            'correlation_id' => $current->correlation_id,
            'event_type' => $eventType,
            'severity' => $overrides['severity'] ?? $current->severity,
            'status' => $overrides['status'] ?? $current->status,
            'customer_impact' => $overrides['customer_impact'] ?? $current->customer_impact,
            'notification_needed' => $overrides['notification_needed'] ?? $current->notification_needed,
            'root_cause' => $overrides['root_cause'] ?? $current->root_cause,
            'resolution' => $overrides['resolution'] ?? $current->resolution,
            'message' => $overrides['message'] ?? null,
            'actor_user_id' => $actor?->id,
        ]);
    }
}
