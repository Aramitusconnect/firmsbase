<?php

namespace App\Services;

use App\Enums\DeploymentMode;
use App\Enums\FleetMigrationRunStatus;
use App\Enums\HealthCheckStatus;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\StatusPageEventStatus;
use App\Models\BackupRestoreTest;
use App\Models\FleetMigrationRun;
use App\Models\HealthCheck;
use App\Models\IncidentEvent;
use App\Models\StatusPageEvent;
use App\ValueObjects\ServiceHealthCurrentState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * OperationsOverviewService — the single evidence-assembling service
 * behind the Operations Overview console. Operations Control Plane
 * addition.
 *
 * Every signal it returns is a triple: a value, whether that value is
 * actually AVAILABLE, and where it came from. Callers render the
 * source; they never render an unavailable value as zero. This shape
 * exists because the failure mode of an operations dashboard is not
 * usually a wrong number — it is a confident number with nothing
 * behind it.
 *
 * A deliberate consequence: this class returns "Not Monitored" and
 * "Not Available" in many places. That is the honest current state of
 * this platform, and a summary page that hid it would be worse than
 * no summary page.
 */
class OperationsOverviewService
{
    /**
     * Upper bound on the recent-change feed. Bounded so the overview
     * stays a fixed number of indexed queries.
     */
    public const RECENT_CHANGE_LIMIT = 25;

    public function __construct(
        private OperationsHealthEvaluationService $health,
        private QueueObservabilityService $queues,
        private SchedulerObservabilityService $scheduler,
        private BackupRestoreCapabilityService $backups,
        private StatusPagePublicationCapabilityService $statusPublication,
        private FleetMigrationSafetyService $fleetSafety,
    ) {}

    /**
     * Platform health roll-up. Fully real — every count comes from
     * recorded observations adjusted for freshness.
     *
     * @return array<string, mixed>
     */
    public function platformHealth(): array
    {
        $summary = $this->health->summary();

        return $summary + [
            'available' => true,
            'source' => 'health_checks observations, freshness-adjusted',
        ];
    }

    /**
     * Incident posture, from the canonical incident domain. Current
     * state per incident is the latest event for its correlation_id.
     *
     * @return array{available: bool, source: string, active: int, critical_active: int, by_status: array<string, int>, unresolved_with_customer_impact: int, awaiting_customer_notification: int}
     */
    public function incidents(): array
    {
        $currentStates = IncidentEvent::query()
            ->whereIn('id', function ($query): void {
                $query->selectRaw('MAX(id)')->from('incident_events')->groupBy('correlation_id');
            })
            ->get();

        $active = $currentStates->reject(fn (IncidentEvent $incident): bool => $incident->isResolved());

        return [
            'available' => true,
            'source' => 'incident_events, latest event per correlation_id',
            'active' => $active->count(),
            'critical_active' => $active->where('severity', IncidentSeverity::Critical)->count(),
            'by_status' => collect(IncidentStatus::cases())
                ->mapWithKeys(fn (IncidentStatus $status): array => [
                    $status->value => $active->where('status', $status)->count(),
                ])
                ->all(),
            'unresolved_with_customer_impact' => $active->where('customer_impact', true)->count(),
            'awaiting_customer_notification' => $active->where('notification_needed', true)->count(),
        ];
    }

    /**
     * Queue and worker posture. Backlog is real; worker liveness and
     * recent throughput are explicitly unavailable, not zero.
     *
     * @return array<string, mixed>
     */
    public function queues(): array
    {
        $observations = $this->queues->observeAll();

        return [
            'available' => true,
            'source' => 'jobs and failed_jobs tables',
            'queue_count' => count($observations),
            'total_pending' => array_sum(array_map(fn ($o): int => $o->pending, $observations)),
            'total_reserved' => array_sum(array_map(fn ($o): int => $o->reserved, $observations)),
            'total_delayed' => array_sum(array_map(fn ($o): int => $o->delayed, $observations)),
            'total_failed' => array_sum(array_map(fn ($o): int => $o->failed, $observations)),
            'oldest_pending_age_seconds' => $this->maxOrNull(array_map(fn ($o): ?int => $o->oldestPendingAgeSeconds, $observations)),
            'oldest_failed_age_seconds' => $this->maxOrNull(array_map(fn ($o): ?int => $o->oldestFailedAgeSeconds, $observations)),
            'attention_signals' => $this->queues->attentionSignals(),
            'worker_evidence' => $this->queues->workerEvidence(),
            'processed_recently_evidence' => $this->queues->processedRecentlyEvidence(),
        ];
    }

    /**
     * Scheduler posture. The heartbeat is real; per-command execution
     * outcomes are not available at all.
     *
     * @return array<string, mixed>
     */
    public function scheduler(): array
    {
        return [
            'available' => true,
            'source' => 'scheduler heartbeat cache and the registered Schedule object',
            'heartbeat' => $this->scheduler->heartbeat(),
            'registered_count' => count($this->scheduler->registeredEntries()),
            'execution_history_available' => $this->scheduler->hasExecutionHistory(),
            'execution_history_reason' => $this->scheduler->executionHistoryUnavailableReason(),
        ];
    }

    /**
     * Data-protection posture. Deliberately reports the absence of a
     * measured RPO/RTO rather than the simulated figures on file.
     *
     * @return array<string, mixed>
     */
    public function dataProtection(): array
    {
        $latest = BackupRestoreTest::query()->whereNull('firm_id')->orderByDesc('id')->first();

        return [
            'available' => true,
            'source' => 'backup_restore_tests plus runner-capability inspection',
            'backup_inventory_available' => $this->backups->hasBackupInventory(),
            'pitr_verified' => $this->backups->hasVerifiedPitr(),
            'verified_restore' => $this->backups->hasVerifiedRestore(),
            'last_recorded_drill_at' => $latest?->completed_at,
            'recorded_figure_qualifier' => $this->backups->recordedFigureQualifier(),
            'target_rpo_seconds' => $latest?->rpo_target_seconds,
            'target_rto_seconds' => $latest?->rto_target_seconds,
            'actual_rpo_label' => $this->backups->actualRpoLabel(),
            'actual_rto_label' => $this->backups->actualRtoLabel(),
        ];
    }

    /**
     * Release traceability. No authoritative release record exists —
     * no release table, no CI/CD deployment record, no ECR image
     * digest, no ECS task definition. The source commit of the
     * running checkout is reported as exactly that and nothing more.
     *
     * @return array{available: bool, source: string, source_commit: ?string, desired_version_available: bool, version_skew_calculable: bool, reason: string}
     */
    public function release(): array
    {
        return [
            'available' => false,
            'source' => 'none — no release record exists in this platform',
            'source_commit' => $this->sourceCommit(),
            'desired_version_available' => false,
            'version_skew_calculable' => false,
            'reason' => 'No authoritative SaaS release source exists: there is no release model, no CI/CD deployment '.
                'record, no container image digest, and no task-definition reference anywhere in this codebase. The '.
                'source commit below is read from the checked-out working tree, which indicates what code is present '.
                'but is not a record that a release was deployed. Because no desired version exists, version skew is '.
                'Not Calculable rather than zero.',
        ];
    }

    /**
     * Dedicated deployment posture. Counts are real; staleness is not
     * decidable because no expected heartbeat cadence is defined.
     *
     * @return array<string, mixed>
     */
    public function deployments(): array
    {
        $dedicatedFirmIds = DB::table('firms')
            ->whereIn('deployment_mode', [DeploymentMode::Dedicated->value, DeploymentMode::PrivateEnterprise->value])
            ->pluck('id');

        return [
            'available' => true,
            'source' => 'firms.deployment_mode',
            'dedicated_count' => $dedicatedFirmIds->count(),
            'heartbeat_staleness_decidable' => false,
            'heartbeat_staleness_reason' => 'No expected heartbeat cadence is defined for dedicated deployments, so '.
                'an age can be measured but overdue cannot be decided.',
            'version_skew_calculable' => false,
            'infrastructure_verification_available' => false,
        ];
    }

    /**
     * Fleet posture. Counts are real rows; the safety verdict is
     * derived from the enumerated control set.
     *
     * @return array<string, mixed>
     */
    public function fleet(): array
    {
        $runs = FleetMigrationRun::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'available' => true,
            'source' => 'fleet_migration_runs',
            'active' => (int) ($runs[FleetMigrationRunStatus::InProgress->value] ?? 0),
            'pending' => (int) ($runs[FleetMigrationRunStatus::Pending->value] ?? 0),
            'halted' => (int) ($runs[FleetMigrationRunStatus::Halted->value] ?? 0),
            'completed' => (int) ($runs[FleetMigrationRunStatus::Completed->value] ?? 0),
            'simulation_only' => $this->fleetSafety->isSimulationOnly(),
            'production_safe' => $this->fleetSafety->isProductionSafe(),
            'missing_controls' => count($this->fleetSafety->missingControls()),
            'canary_available' => false,
        ];
    }

    /**
     * Public communication posture. Reports internal records as
     * internal records while no public endpoint exists.
     *
     * @return array<string, mixed>
     */
    public function statusCommunications(): array
    {
        $current = StatusPageEvent::query()
            ->whereIn('id', function ($query): void {
                $query->selectRaw('MAX(id)')->from('status_page_events')->groupBy('correlation_id');
            })
            ->get();

        return [
            'available' => true,
            'source' => 'status_page_events, latest row per correlation_id',
            'is_publicly_published' => $this->statusPublication->hasPublicPublicationBackend(),
            'publication_semantics' => $this->statusPublication->publicationSemanticsLabel(),
            'published_records' => $current->where('status', StatusPageEventStatus::Published)->count(),
            'unresolved_published_records' => $current
                ->where('status', StatusPageEventStatus::Published)
                ->whereNull('resolved_at')
                ->count(),
            'latest_record_at' => $current->max('created_at'),
            'disclosure' => $this->statusPublication->disclosure(),
        ];
    }

    /**
     * The Requires Attention queue: everything the platform can
     * actually see that needs a human right now, each with the
     * evidence that produced it.
     *
     * Known monitoring gaps are excluded on purpose — they are
     * reported separately as coverage gaps, not as live alerts,
     * because an alert that can never be cleared trains operators to
     * ignore the queue.
     *
     * @return array<int, array{area: string, condition: string, detail: string, severity: string}>
     */
    public function requiresAttention(): array
    {
        $items = [];

        foreach ($this->health->requiringAttention() as $state) {
            $items[] = [
                'area' => 'Service Health',
                'condition' => Str::headline($state->checkType->value),
                'detail' => (string) $state->attentionReason(),
                'severity' => $state->effectiveStatus() === HealthCheckStatus::Unhealthy ? 'critical' : 'warning',
            ];
        }

        foreach ($this->queues->attentionSignals() as $signal) {
            $items[] = [
                'area' => 'Queues',
                'condition' => $signal['signal'].' — '.$signal['queue'],
                'detail' => sprintf('Observed %s against a threshold of %s (source: %s).', $signal['observed'], $signal['threshold'], $signal['source']),
                'severity' => 'warning',
            ];
        }

        $heartbeat = $this->scheduler->heartbeat();

        if (! $heartbeat['observed']) {
            $items[] = [
                'area' => 'Scheduler',
                'condition' => 'Scheduler heartbeat never observed',
                'detail' => 'No heartbeat has ever been recorded, so no scheduled work can be assumed to have run.',
                'severity' => 'critical',
            ];
        } elseif (! $heartbeat['healthy']) {
            $items[] = [
                'area' => 'Scheduler',
                'condition' => 'Scheduler heartbeat stale',
                'detail' => 'Last heartbeat was '.$heartbeat['age_seconds'].'s ago, beyond the staleness window.',
                'severity' => 'critical',
            ];
        }

        $incidents = $this->incidents();

        if ($incidents['critical_active'] > 0) {
            $items[] = [
                'area' => 'Incidents',
                'condition' => $incidents['critical_active'].' active critical incident(s)',
                'detail' => 'Critical incidents are open and unresolved.',
                'severity' => 'critical',
            ];
        }

        if ($incidents['awaiting_customer_notification'] > 0) {
            $items[] = [
                'area' => 'Incidents',
                'condition' => $incidents['awaiting_customer_notification'].' incident(s) flagged as needing customer notification',
                'detail' => 'Flagged for customer communication and not yet resolved. '.$this->statusPublication->publicationSemanticsLabel().'.',
                'severity' => 'warning',
            ];
        }

        $fleet = $this->fleet();

        if ($fleet['halted'] > 0) {
            $items[] = [
                'area' => 'Fleet',
                'condition' => $fleet['halted'].' halted fleet run(s)',
                'detail' => 'A run halted after a failed target and left remaining targets skipped.',
                'severity' => 'warning',
            ];
        }

        return $items;
    }

    /**
     * Monitoring and capability gaps: things this platform knowingly
     * cannot see. Distinct from Requires Attention — nobody can fix
     * these by responding to an alert.
     *
     * @return array<int, array{area: string, gap: string}>
     */
    public function coverageGaps(): array
    {
        $gaps = [];

        foreach ($this->health->monitoringGaps() as $state) {
            $gaps[] = [
                'area' => 'Service Health',
                'gap' => Str::headline($state->checkType->value).' is not monitored — no probe exists.',
            ];
        }

        $gaps[] = ['area' => 'Queues', 'gap' => 'Worker liveness is not monitored — no heartbeat or process registry exists.'];
        $gaps[] = ['area' => 'Queues', 'gap' => 'Recent throughput is not measurable with the database queue driver.'];
        $gaps[] = ['area' => 'Scheduler', 'gap' => 'Per-command execution history does not exist — registration is not execution.'];
        $gaps[] = ['area' => 'Data Protection', 'gap' => 'No backup inventory, no verified PITR, and no real restore has ever been performed.'];
        $gaps[] = ['area' => 'Release', 'gap' => 'No authoritative release source exists — version skew is Not Calculable.'];
        $gaps[] = ['area' => 'Deployments', 'gap' => 'Declared database/storage isolation is never verified against real infrastructure.'];
        $gaps[] = ['area' => 'Fleet', 'gap' => 'Fleet migration is a rehearsal tool — no preflight, canary, batching, or real execution.'];

        if (! $this->statusPublication->hasPublicPublicationBackend()) {
            $gaps[] = ['area' => 'Status', 'gap' => 'No public status endpoint exists — published records are internal only.'];
        }

        return $gaps;
    }

    /**
     * A bounded recent-change feed assembled from existing canonical
     * evidence. No new event stream is created and the audit log is
     * not dumped — these are the operational records that already
     * exist, merged and sorted.
     *
     * @return array<int, array{at: Carbon, area: string, summary: string}>
     */
    public function recentChanges(): array
    {
        $changes = [];

        foreach ($this->recentHealthTransitions() as $change) {
            $changes[] = $change;
        }

        $incidentEvents = IncidentEvent::query()
            ->orderByDesc('id')
            ->limit(self::RECENT_CHANGE_LIMIT)
            ->get();

        foreach ($incidentEvents as $event) {
            $changes[] = [
                'at' => $event->created_at,
                'area' => 'Incident',
                'summary' => sprintf(
                    '%s — severity %s, status %s',
                    Str::headline($event->event_type),
                    $event->severity->value,
                    $event->status->value,
                ),
            ];
        }

        $statusEvents = StatusPageEvent::query()
            ->orderByDesc('id')
            ->limit(self::RECENT_CHANGE_LIMIT)
            ->get();

        foreach ($statusEvents as $event) {
            $changes[] = [
                'at' => $event->created_at,
                'area' => 'Status Record',
                'summary' => sprintf(
                    '%s for %s (%s)',
                    Str::headline($event->event_type),
                    $event->component_affected,
                    $this->statusPublication->publicationSemanticsLabel(),
                ),
            ];
        }

        $fleetRuns = FleetMigrationRun::query()
            ->orderByDesc('id')
            ->limit(self::RECENT_CHANGE_LIMIT)
            ->get();

        foreach ($fleetRuns as $run) {
            $at = $run->completed_at ?? $run->started_at;

            if ($at === null) {
                continue;
            }

            $changes[] = [
                'at' => $at,
                'area' => 'Fleet',
                'summary' => sprintf('%s — %s (simulated)', $run->migration_identifier, $run->status->value),
            ];
        }

        $drills = BackupRestoreTest::query()
            ->whereNull('firm_id')
            ->orderByDesc('id')
            ->limit(self::RECENT_CHANGE_LIMIT)
            ->get();

        foreach ($drills as $drill) {
            $at = $drill->completed_at ?? $drill->started_at;

            if ($at === null) {
                continue;
            }

            $changes[] = [
                'at' => $at,
                'area' => 'Restore Drill',
                'summary' => sprintf('Drill recorded — %s (%s)', $drill->status->value, $this->backups->recordedFigureQualifier()),
            ];
        }

        usort($changes, fn (array $a, array $b): int => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());

        return array_slice($changes, 0, self::RECENT_CHANGE_LIMIT);
    }

    /**
     * Health STATUS TRANSITIONS, not every observation. A sweep every
     * five minutes writes nine rows; showing all of them would bury
     * everything else in the feed. Only rows whose status differs from
     * the previous observation of the same check are changes.
     *
     * @return array<int, array{at: Carbon, area: string, summary: string}>
     */
    private function recentHealthTransitions(): array
    {
        $recent = HealthCheck::query()
            ->whereNull('firm_id')
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_CHANGE_LIMIT * 10)
            ->get();

        $transitions = [];
        $seenStatusByType = [];

        // Walked newest-first: the first time a check's status differs
        // from the next-newer one we have seen, that row is the
        // transition point.
        foreach ($recent->reverse() as $observation) {
            $type = $observation->check_type->value;
            $previous = $seenStatusByType[$type] ?? null;

            if ($previous !== null && $previous !== $observation->status) {
                $transitions[] = [
                    'at' => $observation->checked_at,
                    'area' => 'Health',
                    'summary' => sprintf(
                        '%s changed from %s to %s',
                        Str::headline($type),
                        $previous->label(),
                        $observation->status->label(),
                    ),
                ];
            }

            $seenStatusByType[$type] = $observation->status;
        }

        return $transitions;
    }

    /**
     * @param  array<int, ?int>  $values
     */
    private function maxOrNull(array $values): ?int
    {
        $present = array_filter($values, fn (?int $value): bool => $value !== null);

        return $present === [] ? null : max($present);
    }

    /**
     * The source commit of the running checkout. Reported by the
     * release() method as explicitly NOT a release record.
     */
    private function sourceCommit(): ?string
    {
        try {
            $result = Process::path(base_path())->run('git rev-parse --short HEAD');

            if ($result->successful()) {
                $sha = trim($result->output());

                return $sha !== '' ? $sha : null;
            }
        } catch (\Throwable) {
            // Unavailable is a legitimate answer here.
        }

        return null;
    }

    /**
     * @return array<int, ServiceHealthCurrentState>
     */
    public function healthStates(): array
    {
        return $this->health->currentStates();
    }
}
