<?php

namespace App\Services;

use App\Models\ExportJob;
use App\Models\Firm;
use App\Models\ImportBatch;

/**
 * TenantSafeImportExportPolicyService — the shared cross-firm guard
 * consumed by both ImportApplyService's callers and ExportJobService's
 * callers. Asserts that an ImportBatch/ExportJob truly belongs to the
 * firm the caller believes it is operating as, independent of and in
 * addition to BelongsToTenant's global scope (defense in depth,
 * mirroring FirmUser::assertBelongsToActiveTenant()'s pattern from
 * Phase 1).
 */
class TenantSafeImportExportPolicyService
{
    public function assertImportBatchBelongsToFirm(ImportBatch $batch, Firm $firm): void
    {
        if ($batch->firm_id !== $firm->id) {
            throw new \App\Exceptions\TenantIsolationException(
                "ImportBatch [id={$batch->id}] does not belong to firm [id={$firm->id}]."
            );
        }
    }

    public function assertExportJobBelongsToFirm(ExportJob $job, Firm $firm): void
    {
        if ($job->firm_id !== $firm->id) {
            throw new \App\Exceptions\TenantIsolationException(
                "ExportJob [id={$job->id}] does not belong to firm [id={$firm->id}]."
            );
        }
    }
}
