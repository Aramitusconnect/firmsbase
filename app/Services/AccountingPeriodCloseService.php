<?php

namespace App\Services;

use App\Enums\AccountingPeriodEventType;
use App\Enums\AccountingPeriodStatus;
use App\Enums\ChartOfAccountPurpose;
use App\Models\AccountingPeriod;
use App\Models\AccountingPeriodEvent;
use App\Models\Firm;
use App\Models\FirmUser;
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
 *
 * Accounting Integrity Hardening Pass, item 7: close()/reopen() now
 * assert AccountingEntitlementPolicyService::assertCanApprove()
 * THEMSELVES rather than relying solely on the Filament
 * ClosePeriodAction's own UI-layer visibility check — a service that
 * can be called from anywhere (a future console command, this
 * hardening pass's own AH8 opening-balance service, a later API) must
 * enforce its own authorization, not depend on every caller having
 * already checked. Every transition also writes an immutable
 * AccountingPeriodEvent row (see that model's own docblock) in the
 * SAME transaction as the accounting_periods write.
 */
class AccountingPeriodCloseService
{
    public function __construct(
        private readonly AccountingBalanceService $accountingBalance,
        private readonly AccountingReportingService $reporting,
        private readonly ChartOfAccountsService $chartOfAccounts,
        private readonly TrustBalanceService $trustBalance,
        private readonly AccountingEntitlementPolicyService $entitlementPolicy,
    ) {}

    public function close(Firm $firm, \DateTimeInterface $periodStart, \DateTimeInterface $periodEnd, FirmUser $closedBy): AccountingPeriod
    {
        if ((int) $closedBy->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('The closing user does not belong to this firm.');
        }

        $this->entitlementPolicy->assertCanApprove($closedBy);

        $alreadyClosed = (new TenantContextService)->runWithFirmContext($firm, fn () => AccountingPeriod::query()
            ->where('firm_id', $firm->id)
            ->where('period_start', $periodStart->format('Y-m-d'))
            ->where('period_end', $periodEnd->format('Y-m-d'))
            ->where('status', AccountingPeriodStatus::Closed)
            ->exists());

        if ($alreadyClosed) {
            throw new \RuntimeException('This exact period has already been closed.');
        }

        $cashAccount = $this->chartOfAccounts->resolveByPurpose($firm, ChartOfAccountPurpose::OperatingCash);

        $openingBalanceCents = $cashAccount === null ? null : $this->accountingBalance->accountBalanceAsOf($firm, $cashAccount, $periodStart);
        $closingBalanceCents = $cashAccount === null ? null : $this->accountingBalance->accountBalanceAsOf($firm, $cashAccount, $periodEnd);

        $arSnapshot = $this->reporting->accountsReceivableAging($firm, $periodEnd)->data
            ->map(fn (array $row) => [
                'invoice_id' => $row['invoice']->id,
                'remaining_cents' => $row['remaining_cents'],
                'days_overdue' => $row['days_overdue'],
                'bucket' => $row['bucket'],
            ])->values()->all();

        // Accounting Integrity Hardening Pass, item 6: a true
        // as-of-periodEnd figure, computed by TrustBalanceService::
        // firmTrustLiabilityAsOf() straight from immutable
        // trust_ledger_entries filtered by posted_at — no longer the
        // CURRENT trust_balances cache. A deposit/withdrawal posted
        // after $periodEnd is excluded by construction and can never
        // retroactively change this closed period's own snapshot, even
        // though the live cache used for reporting "today" has since
        // moved on.
        $trustLiabilityCents = $this->trustBalance->firmTrustLiabilityAsOf($firm, $periodEnd);

        $unresolvedExceptions = $this->reporting->reconciliationExceptions($firm)->data
            ->map(fn ($reconciliation) => [
                'trust_reconciliation_id' => $reconciliation->id,
                'trust_account_id' => $reconciliation->trust_account_id,
                'discrepancy_cents' => $reconciliation->discrepancy_cents,
                'client_liability_discrepancy_cents' => $reconciliation->client_liability_discrepancy_cents,
            ])->values()->all();

        return DB::transaction(fn () => (new TenantContextService)->runWithFirmContext($firm, function () use (
            $firm, $periodStart, $periodEnd, $openingBalanceCents, $closingBalanceCents, $arSnapshot, $trustLiabilityCents, $unresolvedExceptions, $closedBy
        ) {
            $period = AccountingPeriod::create([
                'firm_id' => $firm->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => AccountingPeriodStatus::Closed,
                'opening_balance_cents' => $openingBalanceCents,
                'closing_balance_cents' => $closingBalanceCents,
                'ar_snapshot_json' => $arSnapshot,
                'trust_liability_snapshot_json' => [
                    'note' => 'True as-of-period-end figure, computed from immutable trust_ledger_entries filtered by posted_at <= period_end.',
                    'total_cents' => $trustLiabilityCents,
                ],
                'unresolved_exceptions_json' => $unresolvedExceptions,
                'closed_by_firm_user_id' => $closedBy->id,
                'closed_at' => now(),
            ]);

            AccountingPeriodEvent::create([
                'firm_id' => $firm->id,
                'accounting_period_id' => $period->id,
                'event_type' => AccountingPeriodEventType::Closed,
                'actor_firm_user_id' => $closedBy->id,
                'reason' => null,
            ]);

            return $period;
        }));
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

        if (trim($reason) === '') {
            throw new \RuntimeException('A reason is required to reopen a closed accounting period.');
        }

        $this->entitlementPolicy->assertCanApprove($reopenedBy);

        return DB::transaction(fn () => (new TenantContextService)->runWithFirmContext($firm, function () use ($period, $reopenedBy, $reason) {
            // closed_by_firm_user_id/closed_at are deliberately left
            // untouched — the fact this period was previously closed
            // must never be erased by reopening it (Accounting
            // Integrity Hardening Pass, item 7).
            $period->update([
                'status' => AccountingPeriodStatus::Reopened,
                'reopened_by_firm_user_id' => $reopenedBy->id,
                'reopened_at' => now(),
                'reopen_reason' => $reason,
            ]);

            AccountingPeriodEvent::create([
                'firm_id' => $period->firm_id,
                'accounting_period_id' => $period->id,
                'event_type' => AccountingPeriodEventType::Reopened,
                'actor_firm_user_id' => $reopenedBy->id,
                'reason' => $reason,
            ]);

            return $period->fresh();
        }));
    }
}
