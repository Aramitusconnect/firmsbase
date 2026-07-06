<?php

namespace App\Services;

use App\Enums\DeploymentMode;
use App\Enums\FleetMigrationInstanceStatus as InstanceStatus;
use App\Enums\FleetMigrationRunStatus;
use App\Models\Firm;
use App\Models\FleetMigrationInstanceStatus;
use App\Models\FleetMigrationRun;
use App\Models\User;
use App\ValueObjects\FleetMigrationRunSummary;
use Illuminate\Support\Facades\DB;

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
 */
class FleetMigrationOrchestrationService
{
    public function createRun(string $migrationIdentifier, User $initiatedBy): FleetMigrationRun
    {
        return DB::transaction(function () use ($migrationIdentifier, $initiatedBy) {
            $run = FleetMigrationRun::create([
                'migration_identifier' => $migrationIdentifier,
                'status' => FleetMigrationRunStatus::Pending,
                'initiated_by' => $initiatedBy->id,
            ]);

            $dedicatedOrPrivateFirms = Firm::query()
                ->whereIn('deployment_mode', [DeploymentMode::Dedicated->value, DeploymentMode::PrivateEnterprise->value])
                ->get();

            foreach ($dedicatedOrPrivateFirms as $firm) {
                FleetMigrationInstanceStatus::create([
                    'fleet_migration_run_id' => $run->id,
                    'firm_id' => $firm->id,
                    'status' => InstanceStatus::Pending,
                ]);
            }

            return $run->fresh();
        });
    }

    public function begin(FleetMigrationRun $run): FleetMigrationRun
    {
        if ($run->status !== FleetMigrationRunStatus::Pending) {
            throw new \RuntimeException('Only a Pending run can begin.');
        }

        $run->update(['status' => FleetMigrationRunStatus::InProgress, 'started_at' => now()]);

        return $run->fresh();
    }

    /**
     * Records a SIMULATED apply outcome for one firm's instance.
     * $succeeded is entirely caller-supplied — this method performs no
     * real migration of any kind. A failure halts the run and marks
     * every other still-Pending instance Skipped.
     */
    public function applyInstance(FleetMigrationRun $run, Firm $firm, bool $succeeded, ?string $errorDetail = null): FleetMigrationInstanceStatus
    {
        if ($run->status !== FleetMigrationRunStatus::InProgress) {
            throw new \RuntimeException('Instances can only be applied while the run is InProgress.');
        }

        return DB::transaction(function () use ($run, $firm, $succeeded, $errorDetail) {
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

                return $instance->fresh();
            }

            $instance->update([
                'status' => InstanceStatus::Failed,
                'attempted_at' => now(),
                'error_detail' => $errorDetail,
            ]);

            FleetMigrationInstanceStatus::query()
                ->where('fleet_migration_run_id', $run->id)
                ->where('status', InstanceStatus::Pending->value)
                ->update(['status' => InstanceStatus::Skipped->value]);

            $run->update([
                'status' => FleetMigrationRunStatus::Halted,
                'halted_reason' => $errorDetail ?? "Instance for firm {$firm->id} failed.",
            ]);

            return $instance->fresh();
        });
    }

    /**
     * Marks every currently-Applied instance RolledBack and the run
     * itself RolledBack. Pure bookkeeping — no real schema reversal is
     * performed.
     */
    public function rollback(FleetMigrationRun $run): FleetMigrationRun
    {
        if (! in_array($run->status, [FleetMigrationRunStatus::Halted, FleetMigrationRunStatus::Completed], true)) {
            throw new \RuntimeException('Only a Halted or Completed run can be rolled back.');
        }

        return DB::transaction(function () use ($run) {
            FleetMigrationInstanceStatus::query()
                ->where('fleet_migration_run_id', $run->id)
                ->where('status', InstanceStatus::Applied->value)
                ->update(['status' => InstanceStatus::RolledBack->value]);

            $run->update(['status' => FleetMigrationRunStatus::RolledBack]);

            return $run->fresh();
        });
    }

    public function complete(FleetMigrationRun $run): FleetMigrationRun
    {
        $stillPendingOrFailed = FleetMigrationInstanceStatus::query()
            ->where('fleet_migration_run_id', $run->id)
            ->whereIn('status', [InstanceStatus::Pending->value, InstanceStatus::Failed->value])
            ->exists();

        if ($run->status !== FleetMigrationRunStatus::InProgress || $stillPendingOrFailed) {
            throw new \RuntimeException('A run can only complete from InProgress with no Pending/Failed instances remaining.');
        }

        $run->update(['status' => FleetMigrationRunStatus::Completed, 'completed_at' => now()]);

        return $run->fresh();
    }

    public function summarize(FleetMigrationRun $run): FleetMigrationRunSummary
    {
        $counts = FleetMigrationInstanceStatus::query()
            ->where('fleet_migration_run_id', $run->id)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return new FleetMigrationRunSummary(
            status: $run->status,
            pendingCount: (int) ($counts[InstanceStatus::Pending->value] ?? 0),
            appliedCount: (int) ($counts[InstanceStatus::Applied->value] ?? 0),
            failedCount: (int) ($counts[InstanceStatus::Failed->value] ?? 0),
            rolledBackCount: (int) ($counts[InstanceStatus::RolledBack->value] ?? 0),
            skippedCount: (int) ($counts[InstanceStatus::Skipped->value] ?? 0),
        );
    }
}
