<?php

namespace App\Services;

use App\Enums\ExportJobStatus;
use App\Enums\ExportType;
use App\Models\ExportJob;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\ValueObjects\ExportGovernanceDecision;

/**
 * ExportJobService — the only writer of export_jobs. Every job is
 * created firm-scoped (project rule: "export jobs must be firm-scoped")
 * and is gated by ExportGovernancePolicyService BEFORE the job is
 * allowed to move past Requested.
 */
class ExportJobService
{
    public function __construct(
        private readonly ExportGovernancePolicyService $governancePolicyService,
    ) {
    }

    public function request(
        Firm $firm,
        ExportType $exportType,
        ?FirmUser $requestedByFirmUser = null,
        ?PlatformAdmin $requestedByPlatformAdmin = null,
        ?string $reason = null,
        bool $hasActiveLegalHold = false,
        bool $retentionPeriodExpired = false,
        bool $firmIsOffboarding = false,
    ): ExportJob {
        $decision = $this->governancePolicyService->evaluate($firm, $hasActiveLegalHold, $retentionPeriodExpired, $firmIsOffboarding);

        $job = ExportJob::create([
            'firm_id' => $firm->id,
            'export_type' => $exportType,
            'status' => $decision->allowed ? ExportJobStatus::Requested : ExportJobStatus::Blocked,
            'requested_by_firm_user_id' => $requestedByFirmUser?->id,
            'requested_by_platform_admin_id' => $requestedByPlatformAdmin?->id,
            'reason' => $reason,
            'legal_hold_checked' => true,
            'retention_checked' => true,
            'offboarding_checked' => true,
            'failed_reason' => $decision->allowed ? null : $decision->reason,
        ]);

        return $job;
    }

    public function markInProgress(ExportJob $job): ExportJob
    {
        $job->update(['status' => ExportJobStatus::InProgress, 'started_at' => now()]);

        return $job->fresh();
    }

    public function markCompleted(ExportJob $job): ExportJob
    {
        $job->update(['status' => ExportJobStatus::Completed, 'completed_at' => now()]);

        return $job->fresh();
    }

    public function markFailed(ExportJob $job, string $reason): ExportJob
    {
        $job->update(['status' => ExportJobStatus::Failed, 'failed_reason' => $reason]);

        return $job->fresh();
    }
}
