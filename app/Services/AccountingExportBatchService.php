<?php

namespace App\Services;

use App\Enums\AccountingExportBatchStatus;
use App\Enums\AccountingExportTarget;
use App\Models\AccountingExportBatch;
use App\Models\Firm;
use App\Models\FirmUser;

/**
 * AccountingExportBatchService — the only writer of
 * accounting_export_batches. Mirrors Phase 8's ExportJobService 1:1.
 * Gated on the expenses entitlement first (correction #6 — export is
 * blocked when Expenses are disabled; a Blocked batch row is still
 * created so the refusal itself is durable and auditable, mirroring
 * ExportJobService's Blocked-status precedent).
 *
 * accounting_export_batches now has permanent FORCE ROW LEVEL SECURITY
 * (see database/migrations/2026_08_27_950023_prepare_row_level_security_
 * and_force_rls_on_accounting_export_batches_table.php), so every real
 * DB write below runs inside its own runWithFirmContext() call.
 * isExpensesEnabledForFirm() stays OUTSIDE any wrap, unchanged (it
 * self-wraps its own entire body via EntitlementService::isEnabled())
 * — see ExpenseService's own docblock for the full decoy-wrap
 * rationale. request()'s Blocked-status early-return branch and its
 * Requested-status branch each get their own independently-wrapped
 * Create() call.
 */
class AccountingExportBatchService
{
    public function __construct(private readonly AccountingEntitlementPolicyService $entitlementPolicy)
    {
    }

    public function request(
        Firm $firm,
        FirmUser $requestedBy,
        \DateTimeInterface $dateRangeStart,
        \DateTimeInterface $dateRangeEnd,
        AccountingExportTarget $target = AccountingExportTarget::QuickbooksOnline,
    ): AccountingExportBatch {
        if (! $this->entitlementPolicy->isExpensesEnabledForFirm($firm)) {
            return (new TenantContextService())->runWithFirmContext($firm, fn () => AccountingExportBatch::create([
                'firm_id' => $firm->id,
                'export_target' => $target,
                'status' => AccountingExportBatchStatus::Blocked,
                'requested_by_firm_user_id' => $requestedBy->id,
                'date_range_start' => $dateRangeStart,
                'date_range_end' => $dateRangeEnd,
                'failed_reason' => 'Expenses module is disabled for this firm.',
            ]));
        }

        return (new TenantContextService())->runWithFirmContext($firm, fn () => AccountingExportBatch::create([
            'firm_id' => $firm->id,
            'export_target' => $target,
            'status' => AccountingExportBatchStatus::Requested,
            'requested_by_firm_user_id' => $requestedBy->id,
            'date_range_start' => $dateRangeStart,
            'date_range_end' => $dateRangeEnd,
        ]));
    }

    public function markInProgress(AccountingExportBatch $batch): AccountingExportBatch
    {
        $this->assertNotTerminal($batch);

        return (new TenantContextService())->runWithFirmContext($batch->firm_id, function () use ($batch) {
            $batch->update(['status' => AccountingExportBatchStatus::InProgress, 'started_at' => now()]);

            return $batch->fresh();
        });
    }

    public function markCompleted(AccountingExportBatch $batch): AccountingExportBatch
    {
        $this->assertNotTerminal($batch);

        return (new TenantContextService())->runWithFirmContext($batch->firm_id, function () use ($batch) {
            $batch->update(['status' => AccountingExportBatchStatus::Completed, 'completed_at' => now()]);

            return $batch->fresh();
        });
    }

    public function markCompletedWithErrors(AccountingExportBatch $batch): AccountingExportBatch
    {
        $this->assertNotTerminal($batch);

        return (new TenantContextService())->runWithFirmContext($batch->firm_id, function () use ($batch) {
            $batch->update(['status' => AccountingExportBatchStatus::CompletedWithErrors, 'completed_at' => now()]);

            return $batch->fresh();
        });
    }

    public function markFailed(AccountingExportBatch $batch, string $reason): AccountingExportBatch
    {
        $this->assertNotTerminal($batch);

        return (new TenantContextService())->runWithFirmContext($batch->firm_id, function () use ($batch, $reason) {
            $batch->update(['status' => AccountingExportBatchStatus::Failed, 'failed_reason' => $reason]);

            return $batch->fresh();
        });
    }

    /**
     * Completed/CompletedWithErrors/Failed/Blocked batches are never
     * rewritten (correction #9).
     */
    private function assertNotTerminal(AccountingExportBatch $batch): void
    {
        if ($batch->isTerminal()) {
            throw new \RuntimeException('A completed, failed, or blocked export batch cannot be modified.');
        }
    }
}
