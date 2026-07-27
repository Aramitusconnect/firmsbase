<?php

namespace App\Services;

use App\Enums\DeploymentMode;
use App\Enums\FleetMigrationInstanceStatus as InstanceStatus;
use App\Enums\FleetMigrationRunStatus;
use App\Models\Firm;
use App\Models\FleetMigrationInstanceStatus;
use App\Models\FleetMigrationRun;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\ValueObjects\FleetMigrationRunSummary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * FleetMigrationOrchestrationService — SIMULATED ONLY (project rule:
 * fleet migration tooling is simulated/foundation only in Phase 16).
 * Every "apply" outcome is caller-supplied (a boolean), never the
 * result of actually running a migration — this service never calls
 * Artisan::call(), never opens a process, never touches a real
 * external server. It is pure row bookkeeping over
 * fleet_migration_runs/fleet_migration_instance_status.
 *
 * createRun() enrolls every CURRENT dedicated/private firm as a
 * Pending instance. applyInstance(success=false) halts the run and
 * marks every other still-Pending instance Skipped in the same
 * transaction (project rule: failure halts remaining pending
 * instances). rollback() only ever moves Applied rows to RolledBack —
 * it never re-runs or reverses anything for real.
 *
 * fleet_migration_instance_status carries FORCE ROW LEVEL SECURITY
 * (see database/migrations/2026_08_29_970005_prepare_row_level_security_and_force_rls_on_fleet_migration_instance_status_table.php).
 * A single active app.current_firm_id session setting can only see one
 * firm's rows at a time, so every method below that previously ran a
 * single cross-firm bulk query/update (a single exists() check across
 * all firms in complete(), a single bulk UPDATE with no firm_id
 * narrowing in applyInstance()'s failure branch and in rollback(), and
 * a single cross-firm GROUP BY in summarize()) is rewritten here as an
 * explicit per-firm loop: Firm::whereIn('deployment_mode', [Dedicated,
 * PrivateEnterprise])->get(), reading/writing one firm's row at a time
 * inside its own runWithFirmContext() call, merged/aggregated in PHP.
 * No BYPASSRLS, OR TRUE, or admin-role carve-out is used anywhere in
 * this class. Accepted, documented residual gap: a firm whose
 * deployment_mode changes after being enrolled by createRun() but
 * before a later loop in applyInstance()/rollback()/complete()/
 * summarize() would be silently excluded from that later operation,
 * since every method below re-derives its firm set from the CURRENT
 * deployment_mode rather than from the run's own already-created
 * instance rows.
 *
 * Phase 4 (FirmsVault Platform Admin Control Center, "Operations")
 * addition: every method below now ALSO accepts an optional
 * `?PlatformAdmin $platformAdminActor = null` parameter — the
 * resolution to this mission's documented "actor-type gap." For
 * begin()/applyInstance()/rollback()/complete() this is a genuinely
 * "zero actor param" gap exactly like PlanService::activate()/
 * archive() — none of the four previously accepted ANY actor at all,
 * so adding an optional, additive PlatformAdmin-only parameter and
 * recording a PlatformAdminAuditEventRecorder::recordPlatformEvent()
 * row when supplied is a direct, safe application of that established
 * pattern.
 *
 * createRun() is different: `initiated_by` is a NOT NULL foreign key
 * to `users` (see fleet_migration_runs' own migration — unlike
 * incident_events.actor_user_id, this column is NOT nullable), so the
 * "leave the User-typed column null for a platform-admin caller"
 * resolution that works cleanly for IncidentService cannot apply here
 * literally — there is no null to fall back to, and adding a schema
 * migration to relax/duplicate this column was explicitly ruled out
 * for this pass. Resolved by making $initiatedBy optional and, when a
 * platform admin (not a real firm-panel User) initiates a run, writing
 * `initiated_by` against a single, lazily-created, inert sentinel
 * `users` row (see platformSystemActorUser() below) reserved
 * exclusively for this purpose — never a fabricated impersonation of a
 * real person, and structurally incapable of ever authenticating into
 * any panel (no firm_users membership is ever created for it, so
 * User::canAccessPanel() always returns false for it via its own
 * activeFirmUser() === null short-circuit, before any credential check
 * runs). The platform admin's REAL identity is still captured, exactly
 * like every other method here, via a separate
 * recordPlatformEvent() call — `initiated_by` is honest secondary
 * bookkeeping to satisfy the FK, not the attribution record of truth.
 * This is a data-only, idempotent (`firstOrCreate`) row creation, not
 * a schema change — flagged in this pass's own report to the
 * coordinator as a discovered sub-decision beyond the literal
 * "leave null" wording, since fleet_migration_runs.initiated_by's non-
 * nullability was not previously confirmed at the migration level.
 */
class FleetMigrationOrchestrationService
{
    private const AUDIT_CATEGORY = 'deployment_fleet';

    private const SYSTEM_ACTOR_EMAIL = 'platform-system+fleet-migrations@firmsvault.internal';

    public function __construct(
        private readonly PlatformAdminAuditEventRecorder $auditRecorder = new PlatformAdminAuditEventRecorder,
    ) {}

    public function createRun(string $migrationIdentifier, ?User $initiatedBy = null, ?PlatformAdmin $platformAdminActor = null): FleetMigrationRun
    {
        if ($initiatedBy === null && $platformAdminActor === null) {
            throw new \InvalidArgumentException('createRun() requires either a User $initiatedBy or a PlatformAdmin $platformAdminActor.');
        }

        $initiatedByUser = $initiatedBy ?? $this->platformSystemActorUser();

        $tenantContext = new TenantContextService;

        $run = DB::transaction(function () use ($migrationIdentifier, $initiatedByUser, $tenantContext) {
            $run = FleetMigrationRun::create([
                'migration_identifier' => $migrationIdentifier,
                'status' => FleetMigrationRunStatus::Pending,
                'initiated_by' => $initiatedByUser->id,
            ]);

            $dedicatedOrPrivateFirms = Firm::query()
                ->whereIn('deployment_mode', [DeploymentMode::Dedicated->value, DeploymentMode::PrivateEnterprise->value])
                ->get();

            foreach ($dedicatedOrPrivateFirms as $firm) {
                $tenantContext->runWithFirmContext($firm, fn () => FleetMigrationInstanceStatus::create([
                    'fleet_migration_run_id' => $run->id,
                    'firm_id' => $firm->id,
                    'status' => InstanceStatus::Pending,
                ]));
            }

            return $run->fresh();
        });

        if ($platformAdminActor !== null) {
            $this->auditRecorder->recordPlatformEvent($platformAdminActor, 'fleet_migration_run_created', self::AUDIT_CATEGORY, [
                'fleet_migration_run_id' => $run->id,
                'migration_identifier' => $migrationIdentifier,
            ]);
        }

        return $run;
    }

    public function begin(FleetMigrationRun $run, ?PlatformAdmin $platformAdminActor = null): FleetMigrationRun
    {
        if ($run->status !== FleetMigrationRunStatus::Pending) {
            throw new \RuntimeException('Only a Pending run can begin.');
        }

        $run->update(['status' => FleetMigrationRunStatus::InProgress, 'started_at' => now()]);
        $run = $run->fresh();

        if ($platformAdminActor !== null) {
            $this->auditRecorder->recordPlatformEvent($platformAdminActor, 'fleet_migration_run_begun', self::AUDIT_CATEGORY, [
                'fleet_migration_run_id' => $run->id,
            ]);
        }

        return $run;
    }

    /**
     * Records a SIMULATED apply outcome for one firm's instance.
     * $succeeded is entirely caller-supplied — this method performs no
     * real migration of any kind. A failure halts the run and marks
     * every other still-Pending instance Skipped.
     */
    public function applyInstance(FleetMigrationRun $run, Firm $firm, bool $succeeded, ?string $errorDetail = null, ?PlatformAdmin $platformAdminActor = null): FleetMigrationInstanceStatus
    {
        if ($run->status !== FleetMigrationRunStatus::InProgress) {
            throw new \RuntimeException('Instances can only be applied while the run is InProgress.');
        }

        $tenantContext = new TenantContextService;

        $instance = DB::transaction(function () use ($run, $firm, $succeeded, $errorDetail, $tenantContext) {
            $instance = $tenantContext->runWithFirmContext($firm, function () use ($run, $firm, $succeeded, $errorDetail) {
                $instance = FleetMigrationInstanceStatus::query()
                    ->where('fleet_migration_run_id', $run->id)
                    ->where('firm_id', $firm->id)
                    ->firstOrFail();

                if ($succeeded) {
                    $instance->update([
                        'status' => InstanceStatus::Applied,
                        'attempted_at' => now(),
                        'completed_at' => now(),
                    ]);
                } else {
                    $instance->update([
                        'status' => InstanceStatus::Failed,
                        'attempted_at' => now(),
                        'error_detail' => $errorDetail,
                    ]);
                }

                return $instance->fresh();
            });

            if (! $succeeded) {
                $otherDedicatedOrPrivateFirms = Firm::query()
                    ->whereIn('deployment_mode', [DeploymentMode::Dedicated->value, DeploymentMode::PrivateEnterprise->value])
                    ->where('id', '!=', $firm->id)
                    ->get();

                foreach ($otherDedicatedOrPrivateFirms as $otherFirm) {
                    $tenantContext->runWithFirmContext($otherFirm, fn () => FleetMigrationInstanceStatus::query()
                        ->where('fleet_migration_run_id', $run->id)
                        ->where('status', InstanceStatus::Pending->value)
                        ->update(['status' => InstanceStatus::Skipped->value]));
                }

                $run->update([
                    'status' => FleetMigrationRunStatus::Halted,
                    'halted_reason' => $errorDetail ?? "Instance for firm {$firm->id} failed.",
                ]);
            }

            return $instance;
        });

        if ($platformAdminActor !== null) {
            $this->auditRecorder->record($firm, $platformAdminActor, 'fleet_migration_instance_applied', self::AUDIT_CATEGORY, [
                'fleet_migration_run_id' => $run->id,
                'succeeded' => $succeeded,
                'resulting_status' => $instance->status->value,
            ]);
        }

        return $instance;
    }

    /**
     * Marks every currently-Applied instance RolledBack and the run
     * itself RolledBack. Pure bookkeeping — no real schema reversal is
     * performed.
     *
     * Out of this redesign's remit, flagged not fixed: this
     * unconditionally marks fleet_migration_runs.status=RolledBack
     * regardless of per-firm success — a pre-existing product-logic
     * gap, not caused or worsened by this per-firm rewrite.
     */
    public function rollback(FleetMigrationRun $run, ?PlatformAdmin $platformAdminActor = null): FleetMigrationRun
    {
        if (! in_array($run->status, [FleetMigrationRunStatus::Halted, FleetMigrationRunStatus::Completed], true)) {
            throw new \RuntimeException('Only a Halted or Completed run can be rolled back.');
        }

        $tenantContext = new TenantContextService;

        $run = DB::transaction(function () use ($run, $tenantContext) {
            $dedicatedOrPrivateFirms = Firm::query()
                ->whereIn('deployment_mode', [DeploymentMode::Dedicated->value, DeploymentMode::PrivateEnterprise->value])
                ->get();

            foreach ($dedicatedOrPrivateFirms as $firm) {
                $tenantContext->runWithFirmContext($firm, fn () => FleetMigrationInstanceStatus::query()
                    ->where('fleet_migration_run_id', $run->id)
                    ->where('status', InstanceStatus::Applied->value)
                    ->update(['status' => InstanceStatus::RolledBack->value]));
            }

            $run->update(['status' => FleetMigrationRunStatus::RolledBack]);

            return $run->fresh();
        });

        if ($platformAdminActor !== null) {
            $this->auditRecorder->recordPlatformEvent($platformAdminActor, 'fleet_migration_run_rolled_back', self::AUDIT_CATEGORY, [
                'fleet_migration_run_id' => $run->id,
            ]);
        }

        return $run;
    }

    public function complete(FleetMigrationRun $run, ?PlatformAdmin $platformAdminActor = null): FleetMigrationRun
    {
        $tenantContext = new TenantContextService;
        $stillPendingOrFailed = false;

        $dedicatedOrPrivateFirms = Firm::query()
            ->whereIn('deployment_mode', [DeploymentMode::Dedicated->value, DeploymentMode::PrivateEnterprise->value])
            ->get();

        foreach ($dedicatedOrPrivateFirms as $firm) {
            $hasBlockingInstance = $tenantContext->runWithFirmContext($firm, fn () => FleetMigrationInstanceStatus::query()
                ->where('fleet_migration_run_id', $run->id)
                ->whereIn('status', [InstanceStatus::Pending->value, InstanceStatus::Failed->value])
                ->exists());

            if ($hasBlockingInstance) {
                $stillPendingOrFailed = true;
                break;
            }
        }

        if ($run->status !== FleetMigrationRunStatus::InProgress || $stillPendingOrFailed) {
            throw new \RuntimeException('A run can only complete from InProgress with no Pending/Failed instances remaining.');
        }

        $run->update(['status' => FleetMigrationRunStatus::Completed, 'completed_at' => now()]);
        $run = $run->fresh();

        if ($platformAdminActor !== null) {
            $this->auditRecorder->recordPlatformEvent($platformAdminActor, 'fleet_migration_run_completed', self::AUDIT_CATEGORY, [
                'fleet_migration_run_id' => $run->id,
            ]);
        }

        return $run;
    }

    /**
     * Same per-firm-loop-and-merge shape as complete()'s gate above,
     * summed in PHP across every dedicated/private firm rather than
     * short-circuited on first match (this needs the full breakdown,
     * not just a boolean). Return type is intentionally left as
     * FleetMigrationRunSummary (not a bare array, unlike this method's
     * design-doc sketch) — the existing caller
     * (FleetMigrationOrchestrationServiceTest.php) depends on this
     * value object's typed properties (e.g. ->appliedCount,
     * ->totalInstances()), and changing the public return type is a
     * broader interface change out of this activation batch's remit.
     * (ConflictCheckService::summarize() and MatterOpeningService.php
     * call an unrelated, differently-named summarize() method on a
     * different service — not this one.)
     */
    public function summarize(FleetMigrationRun $run): FleetMigrationRunSummary
    {
        $tenantContext = new TenantContextService;
        $counts = [];

        $dedicatedOrPrivateFirms = Firm::query()
            ->whereIn('deployment_mode', [DeploymentMode::Dedicated->value, DeploymentMode::PrivateEnterprise->value])
            ->get();

        foreach ($dedicatedOrPrivateFirms as $firm) {
            $perFirmCounts = $tenantContext->runWithFirmContext($firm, fn () => FleetMigrationInstanceStatus::query()
                ->where('fleet_migration_run_id', $run->id)
                ->where('firm_id', $firm->id)
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status'));

            foreach ($perFirmCounts as $status => $count) {
                $counts[$status] = ($counts[$status] ?? 0) + $count;
            }
        }

        return new FleetMigrationRunSummary(
            status: $run->status,
            pendingCount: (int) ($counts[InstanceStatus::Pending->value] ?? 0),
            appliedCount: (int) ($counts[InstanceStatus::Applied->value] ?? 0),
            failedCount: (int) ($counts[InstanceStatus::Failed->value] ?? 0),
            rolledBackCount: (int) ($counts[InstanceStatus::RolledBack->value] ?? 0),
            skippedCount: (int) ($counts[InstanceStatus::Skipped->value] ?? 0),
        );
    }

    /**
     * Per-firm-loop cross-firm listing of one run's individual instance
     * rows (FORCE RLS — fleet_migration_instance_status), for a
     * platform-admin drill-down view. Mirrors summarize()'s own
     * per-firm loop shape but returns the actual rows rather than
     * aggregated counts.
     *
     * @return Collection<int, FleetMigrationInstanceStatus>
     */
    public function instancesFor(FleetMigrationRun $run): Collection
    {
        $tenantContext = new TenantContextService;
        $instances = collect();

        $dedicatedOrPrivateFirms = Firm::query()
            ->whereIn('deployment_mode', [DeploymentMode::Dedicated->value, DeploymentMode::PrivateEnterprise->value])
            ->orderBy('id')
            ->get();

        foreach ($dedicatedOrPrivateFirms as $firm) {
            $instance = $tenantContext->runWithFirmContext($firm, fn () => FleetMigrationInstanceStatus::query()
                ->where('fleet_migration_run_id', $run->id)
                ->where('firm_id', $firm->id)
                ->first());

            if ($instance !== null) {
                $instances->push($instance);
            }
        }

        return $instances;
    }

    /**
     * The lazily-created, inert sentinel `users` row used as
     * fleet_migration_runs.initiated_by's required FK target when a
     * PlatformAdmin (not a real firm-panel User) initiates a run — see
     * this class's own docblock. Idempotent (`firstOrCreate`), never
     * given any firm_users membership, and therefore incapable of ever
     * authenticating into the firm panel.
     */
    private function platformSystemActorUser(): User
    {
        return User::query()->firstOrCreate(
            ['email' => self::SYSTEM_ACTOR_EMAIL],
            [
                'name' => 'Platform System (Fleet Migration Actor)',
                'password' => Hash::make(Str::random(64)),
            ],
        );
    }
}
