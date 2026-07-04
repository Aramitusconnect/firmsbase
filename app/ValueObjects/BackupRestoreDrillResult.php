<?php

namespace App\ValueObjects;

use App\Enums\BackupRestoreTestStatus;

/**
 * BackupRestoreDrillResult — returned by any BackupRestoreDrillRunner
 * implementation. No real infrastructure backup/restore is performed
 * anywhere in Phase 5 (project rule) — FakeBackupRestoreDrillRunner is
 * the only implementation, used by both BackupRestoreTestService and
 * every test, mirroring Phase 4's VirusScanner/FakeVirusScanner
 * abstraction exactly.
 */
final readonly class BackupRestoreDrillResult
{
    /**
     * @param  array<int, string>  $componentsVerified  subset of:
     *   database_records, documents, app_configuration, queues,
     *   tenant_settings, critical_logs
     */
    public function __construct(
        public BackupRestoreTestStatus $status,
        public array $componentsVerified,
        public int $rpoActualSeconds,
        public int $rtoActualSeconds,
        public ?string $notes = null,
    ) {
    }
}
