<?php

namespace App\Services;

use App\Models\BackupRestoreTest;
use App\Services\BackupRestore\BackupRestoreDrillRunner;
use App\Services\BackupRestore\FakeBackupRestoreDrillRunner;

/**
 * BackupRestoreCapabilityService — answers, from evidence, whether
 * this platform has ever actually restored anything. Operations
 * Control Plane addition.
 *
 * The distinction it enforces is the one §60 of any serious DR policy
 * turns on: a TARGET RPO/RTO is a policy statement, and an ACTUAL
 * RPO/RTO is a measurement taken during a real recovery. The
 * `backup_restore_tests` table stores both in adjacent columns with
 * similar names, and every row in it today was produced by
 * FakeBackupRestoreDrillRunner — a deterministic fixture that returns
 * hardcoded 3600s/7200s figures and performs no I/O whatsoever.
 * Rendering those numbers as "Actual RPO: 3600s" would present a
 * constant from a test double as a disaster-recovery measurement.
 *
 * The capability check is DERIVED from the container binding and the
 * set of implementing classes, not hardcoded, so wiring in a genuine
 * runner automatically changes what the console claims.
 *
 * This service explicitly does NOT offer any way to run a drill.
 * Executing a real restore drill requires provisioning isolated
 * infrastructure and is out of scope for repository work; offering a
 * button that runs the fake would manufacture exactly the false
 * confidence this class exists to prevent.
 */
class BackupRestoreCapabilityService
{
    /**
     * True only when a BackupRestoreDrillRunner implementation exists
     * that is not the fake. Resolved through the container so a real
     * binding registered in a service provider is picked up.
     */
    public function hasRealDrillRunner(): bool
    {
        if (! app()->bound(BackupRestoreDrillRunner::class)) {
            return false;
        }

        try {
            $runner = app(BackupRestoreDrillRunner::class);
        } catch (\Throwable) {
            return false;
        }

        return ! $runner instanceof FakeBackupRestoreDrillRunner;
    }

    /**
     * True when this platform can read a real backup inventory
     * (snapshots, PITR windows, retention) from the infrastructure
     * that holds the backups. It cannot: `backup_restore_tests`
     * records drill outcomes, not backups, and there is no AWS
     * Backup / RDS / S3 client anywhere in this application.
     */
    public function hasBackupInventory(): bool
    {
        return false;
    }

    /**
     * True when point-in-time-recovery availability can be verified.
     * Verifying PITR means asking RDS for its earliest and latest
     * restorable time, which requires an AWS integration and IAM
     * permissions this application does not have.
     */
    public function hasVerifiedPitr(): bool
    {
        return false;
    }

    /**
     * Whether any recorded drill was produced by a real restore.
     *
     * Historical rows carry no "which runner produced this" column,
     * so this deliberately answers conservatively: with no real
     * runner wired in, NO recorded drill can be treated as real,
     * regardless of what its stored numbers say.
     */
    public function hasVerifiedRestore(): bool
    {
        return $this->hasRealDrillRunner() && BackupRestoreTest::query()->exists();
    }

    /**
     * The measured actual RPO, in seconds, or null when none has ever
     * been measured. Returns null — not the recorded number — while
     * only a fake runner exists, because a fixture constant is not a
     * measurement.
     */
    public function measuredActualRpoSeconds(): ?int
    {
        return $this->hasVerifiedRestore()
            ? BackupRestoreTest::query()->whereNotNull('rpo_actual_seconds')->orderByDesc('id')->value('rpo_actual_seconds')
            : null;
    }

    public function measuredActualRtoSeconds(): ?int
    {
        return $this->hasVerifiedRestore()
            ? BackupRestoreTest::query()->whereNotNull('rto_actual_seconds')->orderByDesc('id')->value('rto_actual_seconds')
            : null;
    }

    /**
     * The operator-facing value for an actual RPO/RTO figure. Used by
     * every surface so the wording cannot diverge between pages.
     */
    public function actualRpoLabel(): string
    {
        $measured = $this->measuredActualRpoSeconds();

        return $measured === null ? 'Not Yet Measured' : $measured.'s (measured)';
    }

    public function actualRtoLabel(): string
    {
        $measured = $this->measuredActualRtoSeconds();

        return $measured === null ? 'Not Yet Measured' : $measured.'s (measured)';
    }

    /**
     * How a recorded drill figure should be described. While only the
     * fake runner exists these are simulated inputs, and the label
     * says so wherever the number appears.
     */
    public function recordedFigureQualifier(): string
    {
        return $this->hasRealDrillRunner() ? 'measured' : 'simulated';
    }

    public function disclosure(): string
    {
        if ($this->hasVerifiedRestore()) {
            return 'A real restore drill has been executed and recorded. RPO/RTO figures below are measurements.';
        }

        return 'NO REAL RESTORE HAS EVER BEEN PERFORMED. The only BackupRestoreDrillRunner implementation in this '.
            'codebase is FakeBackupRestoreDrillRunner, which performs no infrastructure I/O and returns fixed '.
            'figures. Every RPO/RTO number recorded below is therefore a SIMULATED input, not a measurement, and '.
            'the actual RPO/RTO this platform can achieve in a real disaster is Not Yet Measured. There is also no '.
            'backup inventory and no verified point-in-time-recovery window: this application cannot read either '.
            'from the infrastructure that would hold them. Backup and restore are NOT production-ready.';
    }
}
