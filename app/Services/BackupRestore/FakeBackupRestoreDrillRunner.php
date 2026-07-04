<?php

namespace App\Services\BackupRestore;

use App\Enums\BackupRestoreTestStatus;
use App\Models\Firm;
use App\ValueObjects\BackupRestoreDrillResult;

/**
 * FakeBackupRestoreDrillRunner — deterministic, no real backup
 * provider, no real infrastructure I/O. Behavior is driven by an
 * explicit constructor configuration so tests can exercise every
 * BackupRestoreTestStatus outcome and every RPO/RTO combination
 * without any external dependency. Defaults to a fully successful
 * drill comfortably inside the master plan's 24h RPO / 8h RTO
 * targets.
 */
class FakeBackupRestoreDrillRunner implements BackupRestoreDrillRunner
{
    /**
     * @param  array<int, string>  $componentsVerified
     */
    public function __construct(
        private BackupRestoreTestStatus $status = BackupRestoreTestStatus::Passed,
        private array $componentsVerified = [
            'database_records', 'documents', 'app_configuration',
            'queues', 'tenant_settings', 'critical_logs',
        ],
        private int $rpoActualSeconds = 3600,
        private int $rtoActualSeconds = 7200,
        private ?string $notes = 'Simulated drill — no real infrastructure backup/restore performed.',
    ) {
    }

    public function run(?Firm $firm): BackupRestoreDrillResult
    {
        return new BackupRestoreDrillResult(
            status: $this->status,
            componentsVerified: $this->componentsVerified,
            rpoActualSeconds: $this->rpoActualSeconds,
            rtoActualSeconds: $this->rtoActualSeconds,
            notes: $this->notes,
        );
    }
}
