<?php

namespace App\Services;

use App\Enums\AccountingPeriodStatus;
use App\Enums\ChartOfAccountType;
use App\Models\AccountingPeriod;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TrustBalance;
use Illuminate\Support\Facades\DB;

/**
 * AccountingPeriodCloseService — Phase K, month-end close. The only
 * writer of accounting_periods. close()/reopen() are the sole
 * transitions; a closed period is enforced by
 * AccountingJournalPostingService::post() rejecting any new posting
 * dated inside it — this service does not itself block postings, it
 * only creates the fact a closed period exists.
 *
 * Reuses AccountingReportingService (Phase J) and AccountingBalanceService/
 * TrustBalanceService for every figure it snapshots — this service
 * computes nothing new itself, it only decides WHEN to snapshot and
 * persists the result.
 */
class AccountingPeriodCloseService
{
    public function __construct(
        private readonly AccountingBalanceService $accountingBalance,
        private readonly AccountingReportingService $reporting,
        private readonly ChartOfAccountsService $chartOfAccounts,
        private readonly TrustBalanceService $trustBalance,
    ) {}

    public function close(Firm $firm, \DateTimeInterface $periodStart, \DateTimeInterface $periodEnd, FirmUser $closedBy): AccountingPeriod
    {
        if ((int) $closedBy->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('The closing user does not belong to this firm.');
        }

        $alreadyClosed = (new TenantContextService)->runWithFirmContext($firm, fn () => AccountingPeriod::query()
            ->where('firm_id', $firm->id)
            ->where('period_start', $periodStart->format('Y-m-d'))
            ->where('period_end', $periodEnd->format('Y-m-d'))
            ->where('status', AccountingPeriodStatus::Closed)
            ->exists());

        if ($alreadyClosed) {
            throw new \RuntimeException('This exact period has already been closed.');
        }

        $cashAccount = $this->chartOfAccounts->resolveActiveAccountByType($firm, ChartOfAccountType::Asset);

        $openingBalanceCents = $cashAccount === null ? null : $this->accountingBalance->accountBalanceAsOf($firm, $cashAccount, $periodStart);
        $closingBalanceCents = $cashAccount === null ? null : $this->accountingBalance->accountBalanceAsOf($firm, $cashAccount, $periodEnd);

        $arSnapshot = $this->reporting->accountsReceivableAging($firm, $periodEnd)->data
            ->map(fn (array $row) => [
                'invoice_id' => $row['invoice']->id,
                'remaining_cents' => $row['remaining_cents'],
                'days_overdue' => $row['days_overdue'],
                'bucket' => $row['bucket'],
            ])->values()->all();

        // Trust liability is a CURRENT balance, not a point-in-time
        // recomputation (TrustBalanceService, deliberately unmodified
        // per the extend-never-parallel mandate, has no historical/
        // as-of query) — this snapshot is explicitly labeled as such
        // rather than presented as a true as-of-periodEnd figure.
        $trustLiabilityCents = (new TenantContextService)->runWithFirmContext($firm, fn () => TrustBalance::query()
            ->where('firm_id', $firm->id)
            ->sum('balance_cents'));

        $unresolvedExceptions = $this->reporting->reconciliationExceptions($firm)->data
            ->map(fn ($reconciliation) => [
                'trust_reconciliation_id' => $reconciliation->id,
                'trust_account_id' => $reconciliation->trust_account_id,
                'discrepancy_cents' => $reconciliation->discrepancy_cents,
                'client_liability_discrepancy_cents' => $reconciliation->client_liability_discrepancy_cents,
            ])->values()->all();

        return DB::transaction(fn () => (new TenantContextService)->runWithFirmContext($firm, fn () => AccountingPeriod::create([
            'firm_id' => $firm->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => AccountingPeriodStatus::Closed,
            'opening_balance_cents' => $openingBalanceCents,
            'closing_balance_cents' => $closingBalanceCents,
            'ar_snapshot_json' => $arSnapshot,
            'trust_liability_snapshot_json' => [
                'note' => 'Current trust balance at close time, not a true as-of-period-end historical figure.',
                'total_cents' => $trustLiabilityCents,
            ],
            'unresolved_exceptions_json' => $unresolvedExceptions,
            'closed_by_firm_user_id' => $closedBy->id,
            'closed_at' => now(),
        ])));
    }

    /**
     * The only way to allow new postings back into an already-closed
     * period. Never itself posts, corrects, or mutates any journal
     * entry — it only changes accounting_periods.status so
     * AccountingJournalPostingService::post() stops rejecting entries
     * dated inside this period. A reopened period is not automatically
     * re-closed; a firm must explicitly close() it again.
     */
    public function reopen(Firm $firm, AccountingPeriod $period, FirmUser $reopenedBy, string $reason): AccountingPeriod
    {
        if ((int) $period->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This accounting period does not belong to this firm.');
        }

        if ($period->status !== AccountingPeriodStatus::Closed) {
            throw new \RuntimeException('Only a closed period can be reopened.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($period, $reopenedBy, $reason) {
            $period->update([
                'status' => AccountingPeriodStatus::Reopened,
                'reopened_by_firm_user_id' => $reopenedBy->id,
                'reopened_at' => now(),
                'reopen_reason' => $reason,
            ]);

            return $period->fresh();
        });
    }
}
