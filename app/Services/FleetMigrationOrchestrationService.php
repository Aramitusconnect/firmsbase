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
 */
class FleetMigrationOrchestrationService
{
    public function createRun(string $migrationIdentifier, User $initiatedBy): FleetMigrationRun
    {
        $tenantContext = new TenantContextService();

        return DB::transaction(function () use ($migrationIdentifier, $initiatedBy, $tenantContext) {
            $run = FleetMigrationRun::create([
                'migration_identifier' => $migrationIdentifier,
                'status' => FleetMigrationRunStatus::Pending,
                'initiated_by' => $initiatedBy->id,
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

        $tenantContext = new TenantContextService();

        return DB::transaction(function () use ($run, $firm, $succeeded, $errorDetail, $tenantContext) {
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
    public function rollback(FleetMigrationRun $run): FleetMigrationRun
    {
        if (! in_array($run->status, [FleetMigrationRunStatus::Halted, FleetMigrationRunStatus::Completed], true)) {
            throw new \RuntimeException('Only a Halted or Completed run can be rolled back.');
        }

        $tenantContext = new TenantContextService();

        return DB::transaction(function () use ($run, $tenantContext) {
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
    }

    public function complete(FleetMigrationRun $run): FleetMigrationRun
    {
        $tenantContext = new TenantContextService();
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

        return $run->fresh();
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
        $tenantContext = new TenantContextService();
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
}
