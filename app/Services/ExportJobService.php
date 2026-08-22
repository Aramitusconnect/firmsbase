<?php

namespace App\Services;

use App\Enums\ExportJobStatus;
use App\Enums\ExportType;
use App\Models\ExportJob;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;

/**
 * ExportJobService — the only writer of export_jobs. Every job is
 * created firm-scoped (project rule: "export jobs must be firm-scoped")
 * and is gated by ExportGovernancePolicyService BEFORE the job is
 * allowed to move past Requested.
 *
 * export_jobs carries FORCE ROW LEVEL SECURITY (see database/migrations/
 * 2026_08_29_970001_prepare_row_level_security_and_force_rls_on_export_jobs_table.php),
 * so every write here runs under a runWithFirmContext() wrap, keyed on
 * the firm already known at each call site — never a nested self-wrap
 * inside an already-active outer context.
 */
class ExportJobService
{
    public function __construct(
        private readonly ExportGovernancePolicyService $governancePolicyService,
    ) {}

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

        $job = (new TenantContextService)->runWithFirmContext($firm, fn () => ExportJob::create([
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
        ]));

        return $job;
    }

    public function markInProgress(ExportJob $job): ExportJob
    {
        return (new TenantContextService)->runWithFirmContext($job->firm_id, function () use ($job) {
            $job->update(['status' => ExportJobStatus::InProgress, 'started_at' => now()]);

            return $job->fresh();
        });
    }

    public function markCompleted(ExportJob $job): ExportJob
    {
        return (new TenantContextService)->runWithFirmContext($job->firm_id, function () use ($job) {
            $job->update(['status' => ExportJobStatus::Completed, 'completed_at' => now()]);

            return $job->fresh();
        });
    }

    public function markFailed(ExportJob $job, string $reason): ExportJob
    {
        return (new TenantContextService)->runWithFirmContext($job->firm_id, function () use ($job, $reason) {
            $job->update(['status' => ExportJobStatus::Failed, 'failed_reason' => $reason]);

            return $job->fresh();
        });
    }
}
