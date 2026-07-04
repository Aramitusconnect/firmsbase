<?php

namespace App\Services;

use App\Models\BackupRestoreTest;
use App\Models\Firm;
use App\Services\BackupRestore\BackupRestoreDrillRunner;

/**
 * BackupRestoreTestService — the only place backup_restore_tests rows
 * are created. Never performs a real infrastructure backup/restore
 * (project rule) — it records the result of whatever
 * BackupRestoreDrillRunner it is given. RPO/RTO targets default to the
 * master plan's controls (24h/8h maximum before paid launch unless a
 * stricter target is approved) but can be overridden per call when a
 * stricter target has been approved.
 */
class BackupRestoreTestService
{
    public function runDrill(
        BackupRestoreDrillRunner $runner,
        ?Firm $firm = null,
        int $rpoTargetSeconds = 86400,
        int $rtoTargetSeconds = 28800,
    ): BackupRestoreTest {
        $startedAt = now();
        $result = $runner->run($firm);

        return BackupRestoreTest::create([
            'firm_id' => $firm?->id,
            'status' => $result->status,
            'components_verified_json' => $result->componentsVerified,
            'rpo_target_seconds' => $rpoTargetSeconds,
            'rto_target_seconds' => $rtoTargetSeconds,
            'rpo_actual_seconds' => $result->rpoActualSeconds,
            'rto_actual_seconds' => $result->rtoActualSeconds,
            'started_at' => $startedAt,
            'completed_at' => now(),
            'notes' => $result->notes,
        ]);
    }

    /**
     * hotfix 01: ordered by id DESC rather than completed_at — multiple
     * drills can complete within the same wall-clock second (as
     * FakeBackupRestoreDrillRunner-driven tests routinely do), and
     * timestamp-only ordering is not deterministic in that case. Row
     * insertion order (the bigint id) is the reliable "most recent"
     * signal.
     */
    public function latestFor(?Firm $firm): ?BackupRestoreTest
    {
        return BackupRestoreTest::query()
            ->when($firm, fn ($q) => $q->where('firm_id', $firm->id), fn ($q) => $q->whereNull('firm_id'))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * "Restore testing must recover database records, documents, app
     * configuration, queues, tenant settings, and critical logs"
     * (PDF) — true only when every one of those 6 components was
     * verified AND the drill both passed and met its RPO/RTO targets.
     */
    public function fullyVerified(BackupRestoreTest $test): bool
    {
        $required = ['database_records', 'documents', 'app_configuration', 'queues', 'tenant_settings', 'critical_logs'];
        $verified = $test->components_verified_json ?? [];

        return $test->meetsTargets() && empty(array_diff($required, $verified));
    }
}
