<?php

namespace App\Services;

/**
 * FleetMigrationSafetyService — states, in one canonical place, which
 * fleet-orchestration safety controls actually exist. Operations
 * Control Plane addition.
 *
 * FleetMigrationOrchestrationService is explicitly simulation-only:
 * every "apply" outcome is a boolean the caller supplies, and the
 * service never runs a migration, opens a process, or contacts a
 * deployment. It is row bookkeeping. That is a legitimate rehearsal
 * tool — but a run record that reads "Completed" looks identical to
 * one produced by a real, safe rollout, and the gap between those two
 * things is where fleet-wide outages live.
 *
 * This service enumerates the controls a production fleet
 * orchestrator would need and reports honestly that they are absent,
 * so the console can label the tool as rehearsal rather than
 * orchestration and the readiness verdict can be derived rather than
 * asserted.
 *
 * Nothing here makes execution easier. It exists to make the absence
 * of safety legible.
 */
class FleetMigrationSafetyService
{
    /**
     * The safety controls required before any fleet migration tool
     * could be considered production-safe, each with its real current
     * state in this codebase.
     *
     * @return array<int, array{control: string, present: bool, detail: string}>
     */
    public function controls(): array
    {
        return [
            [
                'control' => 'Real execution',
                'present' => false,
                'detail' => 'Outcomes are caller-supplied booleans. No migration is ever run against any deployment.',
            ],
            [
                'control' => 'Target eligibility gating',
                'present' => false,
                'detail' => 'Every dedicated/private firm is enrolled unconditionally at run creation. Health, heartbeat freshness, and current version are not consulted.',
            ],
            [
                'control' => 'Preflight checks',
                'present' => false,
                'detail' => 'No reachability, schema-compatibility, version-compatibility, or maintenance-conflict check exists.',
            ],
            [
                'control' => 'Backup readiness gate',
                'present' => false,
                'detail' => 'No recent-restore-point requirement is enforced before a target is migrated. No backup inventory exists to enforce it against.',
            ],
            [
                'control' => 'Canary stage',
                'present' => false,
                'detail' => 'No canary subset, no canary success criteria, and no post-canary verification stage exist.',
            ],
            [
                'control' => 'Bounded concurrency / batching',
                'present' => false,
                'detail' => 'There is no batch concept. Targets are applied one at a time by explicit operator action, which bounds blast radius only because nothing is automated.',
            ],
            [
                'control' => 'Failure threshold',
                'present' => false,
                'detail' => 'No configurable threshold exists. A single failure halts the run and skips all remaining pending targets — a fixed rule, not a configured one.',
            ],
            [
                'control' => 'Pause / resume',
                'present' => false,
                'detail' => 'The run state machine supports Pending, InProgress, Halted, Completed and RolledBack. There is no Paused state and no resume path.',
            ],
            [
                'control' => 'Halt propagation',
                'present' => true,
                'detail' => 'A failure marks the run Halted and marks every still-Pending target Skipped in the same transaction, so no further target can be applied.',
            ],
            [
                'control' => 'Per-target results',
                'present' => true,
                'detail' => 'Each target carries its own status, attempted_at, completed_at and error_detail, so an aggregate result cannot hide an individual failure.',
            ],
            [
                'control' => 'Reversible rollback',
                'present' => false,
                'detail' => 'rollback() only relabels Applied rows as RolledBack. No schema or application change is reversed, because none was ever made.',
            ],
        ];
    }

    /**
     * @return array<int, array{control: string, present: bool, detail: string}>
     */
    public function missingControls(): array
    {
        return array_values(array_filter($this->controls(), fn (array $control): bool => ! $control['present']));
    }

    /**
     * Derived, never asserted: production-safe requires every control
     * present. It is currently false, and the reason is enumerable.
     */
    public function isProductionSafe(): bool
    {
        return $this->missingControls() === [];
    }

    /**
     * True while the orchestrator cannot perform a real migration.
     * Drives the "rehearsal, not rollout" labelling throughout the
     * fleet UI.
     */
    public function isSimulationOnly(): bool
    {
        return true;
    }

    public function disclosure(): string
    {
        return 'REHEARSAL ONLY — THIS IS NOT A FLEET ROLLOUT TOOL. No migration is ever executed against any '.
            'deployment: each target\'s outcome is a value an operator types in, and the run record is bookkeeping '.
            'for a planning exercise. A run that reads "Completed" means every outcome was entered as successful, '.
            'not that anything was migrated. Rollback relabels rows and reverses nothing. Preflight, backup '.
            'readiness, canary, batching, configurable failure thresholds and pause/resume do not exist.';
    }

    /**
     * The label for a run's status, qualified so a simulated
     * "Completed" is never read as a completed rollout.
     */
    public function statusQualifier(): string
    {
        return $this->isSimulationOnly() ? ' (simulated)' : '';
    }
}
