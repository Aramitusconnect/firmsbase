<?php

namespace App\Services\BackupRestore;

use App\Models\Firm;
use App\ValueObjects\BackupRestoreDrillResult;

/**
 * BackupRestoreDrillRunner — the abstraction every restore drill goes
 * through. No production implementation performing a real
 * infrastructure backup/restore ships in Phase 5 (project rule) —
 * FakeBackupRestoreDrillRunner is the only implementation, used by
 * both BackupRestoreTestService and every test. Mirrors Phase 4's
 * VirusScanner/FakeVirusScanner abstraction exactly.
 */
interface BackupRestoreDrillRunner
{
    public function run(?Firm $firm): BackupRestoreDrillResult;
}
