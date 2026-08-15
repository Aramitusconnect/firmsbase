<?php

namespace App\Services;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\Firm;
use App\Models\IncidentEvent;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
 * foundation" the master plan requires.
 *
 * Phase 4 (FirmsVault Platform Admin Control Center, "Operations")
 * addition: every method below now ALSO accepts an optional
 * `?PlatformAdmin $platformAdminActor = null` parameter — the
 * resolution to this mission's documented "actor-type gap"
 * (PlatformAdmin has no relation to the firm-panel User model, but
 * `actor_user_id` is typed/FK'd to `users`). `actor_user_id` is a
 * NULLABLE FK (see incident_events' own migration), so a platform-
 * admin-initiated event simply leaves it null — exactly like every
 * existing caller that passes no `?User $actor` today — while the
 * admin's real identity is captured via
 * PlatformAdminAuditEventRecorder::recordPlatformEvent()/record()
 * (firm-less vs firm-scoped variant, matching each method's own $firm
 * parameter), mirroring PlanService::activate()/archive()'s exact
 * "optional actor, additive, recordPlatformEvent when supplied"
 * shape. When $platformAdminActor is null (every pre-existing caller
 * — only tests call these methods directly today), behavior is
 * byte-for-byte unchanged from before this addition.
 */
class IncidentService
{
    private const AUDIT_CATEGORY = 'operations_incident';

    public function __construct(
        private readonly PlatformAdminAuditEventRecorder $auditRecorder = new PlatformAdminAuditEventRecorder,
    ) {}

    public function open(
        ?Firm $firm,
        IncidentSeverity $severity,
        string $message,
        bool $customerImpact = false,
        bool $notificationNeeded = false,
        ?User $actor = null,
        ?PlatformAdmin $platformAdminActor = null,
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

        $created = $firm
            ? $tenantContext->runWithFirmContext($firm, $create)
            : $tenantContext->runWithoutFirmContext($create);

        $this->recordPlatformAdminAudit($firm, $platformAdminActor, 'incident_opened', [
            'correlation_id' => $correlationId,
            'severity' => $severity->value,
            'customer_impact' => $customerImpact,
            'notification_needed' => $notificationNeeded,
        ]);

        return $created;
    }

    public function updateSeverity(?Firm $firm, string $correlationId, IncidentSeverity $severity, ?User $actor = null, ?PlatformAdmin $platformAdminActor = null): IncidentEvent
    {
        $tenantContext = app(TenantContextService::class);

        $body = function () use ($correlationId, $severity, $actor) {
            $current = $this->currentState($correlationId);

            return $this->appendEvent($current, 'severity_changed', ['severity' => $severity], $actor);
        };

        $result = $firm
            ? $tenantContext->runWithFirmContext($firm, $body)
            : $tenantContext->runWithoutFirmContext($body);

        $this->recordPlatformAdminAudit($firm, $platformAdminActor, 'incident_severity_updated', [
            'correlation_id' => $correlationId,
            'severity' => $severity->value,
        ]);

        return $result;
    }

    public function updateStatus(?Firm $firm, string $correlationId, IncidentStatus $status, ?User $actor = null, ?PlatformAdmin $platformAdminActor = null): IncidentEvent
    {
        $tenantContext = app(TenantContextService::class);

        $body = function () use ($correlationId, $status, $actor) {
            $current = $this->currentState($correlationId);

            return $this->appendEvent($current, 'status_changed', ['status' => $status], $actor);
        };

        $result = $firm
            ? $tenantContext->runWithFirmContext($firm, $body)
            : $tenantContext->runWithoutFirmContext($body);

        $this->recordPlatformAdminAudit($firm, $platformAdminActor, 'incident_status_updated', [
            'correlation_id' => $correlationId,
            'status' => $status->value,
        ]);

        return $result;
    }

    public function recordRootCause(?Firm $firm, string $correlationId, string $rootCause, ?User $actor = null, ?PlatformAdmin $platformAdminActor = null): IncidentEvent
    {
        $tenantContext = app(TenantContextService::class);

        $body = function () use ($correlationId, $rootCause, $actor) {
            $current = $this->currentState($correlationId);

            return $this->appendEvent($current, 'root_cause_added', ['root_cause' => $rootCause], $actor);
        };

        $result = $firm
            ? $tenantContext->runWithFirmContext($firm, $body)
            : $tenantContext->runWithoutFirmContext($body);

        $this->recordPlatformAdminAudit($firm, $platformAdminActor, 'incident_root_cause_recorded', [
            'correlation_id' => $correlationId,
        ]);

        return $result;
    }

    public function flagCustomerImpact(?Firm $firm, string $correlationId, bool $customerImpact, ?User $actor = null, ?PlatformAdmin $platformAdminActor = null): IncidentEvent
    {
        $tenantContext = app(TenantContextService::class);

        $body = function () use ($correlationId, $customerImpact, $actor) {
            $current = $this->currentState($correlationId);

            return $this->appendEvent($current, 'customer_impact_flagged', ['customer_impact' => $customerImpact], $actor);
        };

        $result = $firm
            ? $tenantContext->runWithFirmContext($firm, $body)
            : $tenantContext->runWithoutFirmContext($body);

        $this->recordPlatformAdminAudit($firm, $platformAdminActor, 'incident_customer_impact_flagged', [
            'correlation_id' => $correlationId,
            'customer_impact' => $customerImpact,
        ]);

        return $result;
    }

    public function flagNotificationNeeded(?Firm $firm, string $correlationId, bool $notificationNeeded, ?User $actor = null, ?PlatformAdmin $platformAdminActor = null): IncidentEvent
    {
        $tenantContext = app(TenantContextService::class);

        $body = function () use ($correlationId, $notificationNeeded, $actor) {
            $current = $this->currentState($correlationId);

            return $this->appendEvent($current, 'notification_needed_flagged', ['notification_needed' => $notificationNeeded], $actor);
        };

        $result = $firm
            ? $tenantContext->runWithFirmContext($firm, $body)
            : $tenantContext->runWithoutFirmContext($body);

        $this->recordPlatformAdminAudit($firm, $platformAdminActor, 'incident_notification_needed_flagged', [
            'correlation_id' => $correlationId,
            'notification_needed' => $notificationNeeded,
        ]);

        return $result;
    }

    public function resolve(?Firm $firm, string $correlationId, string $resolution, ?User $actor = null, ?PlatformAdmin $platformAdminActor = null): IncidentEvent
    {
        $tenantContext = app(TenantContextService::class);

        $body = function () use ($correlationId, $resolution, $actor) {
            $current = $this->currentState($correlationId);

            return $this->appendEvent($current, 'resolved', ['status' => IncidentStatus::Resolved, 'resolution' => $resolution], $actor);
        };

        $result = $firm
            ? $tenantContext->runWithFirmContext($firm, $body)
            : $tenantContext->runWithoutFirmContext($body);

        $this->recordPlatformAdminAudit($firm, $platformAdminActor, 'incident_resolved', [
            'correlation_id' => $correlationId,
        ]);

        return $result;
    }

    public function currentState(string $correlationId): IncidentEvent
    {
        return IncidentEvent::query()
            ->where('correlation_id', $correlationId)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * @return Collection<int, IncidentEvent>
     */
    public function timeline(string $correlationId)
    {
        return IncidentEvent::query()
            ->where('correlation_id', $correlationId)
            ->oldest('id')
            ->get();
    }

    /**
     * Derived, evidence-backed facts about one incident, computed
     * from its own append-only timeline. Operations Control Plane
     * addition — read-only, no schema change.
     *
     * `incident_events` has no detected_at, acknowledged_at or
     * resolved_at column, but it does not need them: the timeline
     * already records exactly when the incident was opened and when
     * it was resolved, because those are rows. Reading them is real
     * evidence; adding columns to store the same facts twice would
     * be a schema change with no new information in it.
     *
     * What genuinely CANNOT be derived, and is therefore absent
     * rather than approximated: incident ownership (commander,
     * technical lead, communications lead) and affected components.
     * No column, relation, or event type carries any of them. See
     * ownershipEvidence().
     *
     * @return array{detected_at: ?Carbon, resolved_at: ?Carbon, duration_seconds: ?int, event_count: int}
     */
    public function derivedFacts(string $correlationId): array
    {
        $timeline = $this->timeline($correlationId);

        $detectedAt = $timeline->first()?->created_at;
        $resolvedAt = $timeline
            ->last(fn (IncidentEvent $event): bool => $event->status === IncidentStatus::Resolved)
            ?->created_at;

        return [
            'detected_at' => $detectedAt,
            'resolved_at' => $resolvedAt,
            'duration_seconds' => ($detectedAt !== null && $resolvedAt !== null)
                ? max(0, (int) $detectedAt->diffInSeconds($resolvedAt, absolute: true))
                : null,
            'event_count' => $timeline->count(),
        ];
    }

    /**
     * Incident ownership evidence. There is none.
     *
     * Returned explicitly so every caller renders "Not Recorded"
     * rather than leaving the field silently blank, which reads as
     * "nobody has been assigned yet" when the truth is "this platform
     * cannot record an assignment at all". Assigning an incident
     * commander requires a schema change and owner approval.
     *
     * @return array{available: bool, reason: string, incident_commander: null, technical_lead: null, communications_lead: null}
     */
    public function ownershipEvidence(): array
    {
        return [
            'available' => false,
            'reason' => 'incident_events records no owner, commander, or lead of any kind — there is no column, '.
                'relation, or event type for it. Ownership cannot be assigned or displayed without a schema change. '.
                'actor_user_id records who performed each individual event, which is attribution, not ownership.',
            'incident_commander' => null,
            'technical_lead' => null,
            'communications_lead' => null,
        ];
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

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordPlatformAdminAudit(?Firm $firm, ?PlatformAdmin $platformAdminActor, string $eventType, array $metadata): void
    {
        if ($platformAdminActor === null) {
            return;
        }

        if ($firm !== null) {
            $this->auditRecorder->record($firm, $platformAdminActor, $eventType, self::AUDIT_CATEGORY, $metadata);

            return;
        }

        $this->auditRecorder->recordPlatformEvent($platformAdminActor, $eventType, self::AUDIT_CATEGORY, $metadata);
    }
}
