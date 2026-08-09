<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\TrustReconciliationStatus;
use App\Models\AccountingJournalEntry;
use App\Models\Firm;
use App\Models\InvoiceWriteOff;
use App\Models\PaymentAllocation;
use App\Models\PaymentReversal;
use App\Models\TrustAccount;
use App\Models\TrustLedgerEntry;
use App\Models\TrustReconciliation;
use App\ValueObjects\AccountingReport;
use Illuminate\Support\Collection;

/**
 * AccountingReportingService — Phase J: the ONE centralized place
 * financial reporting/query logic lives, so a Filament page (Phase L)
 * never computes a number itself, only renders what this service
 * returns. A thin composer over the already-canonical sources of
 * truth (AccountingBalanceService, TrustBalanceService,
 * AccountingEarnedFeeService, AccountingJournalEntry/TrustLedgerEntry,
 * PaymentAllocation, PaymentReversal, InvoiceWriteOff, TrustReconciliation,
 * OperatingLedgerBankMatchingService) — this service introduces no new
 * calculation of its own for anything those already compute; every
 * method below either queries directly or delegates to one of them.
 *
 * Every method takes an explicit Firm and every underlying query is
 * scoped by firm_id — no report ever aggregates across firms.
 */
class AccountingReportingService
{
    public function __construct(
        private readonly AccountingBalanceService $accountingBalance,
        private readonly TrustBalanceService $trustBalance,
        private readonly AccountingEarnedFeeService $earnedFee,
    ) {
    }

    public function operatingLedger(Firm $firm, \DateTimeInterface $periodStart, \DateTimeInterface $periodEnd): AccountingReport
    {
        $entries = (new TenantContextService)->runWithFirmContext($firm, fn () => AccountingJournalEntry::query()
            ->where('firm_id', $firm->id)
            ->whereBetween('entry_date', [$periodStart, $periodEnd])
            ->with('postings.chartOfAccount')
            ->orderBy('entry_date')
            ->get());

        return new AccountingReport($firm->id, 'operating_ledger', $periodStart, $periodEnd, $entries, now());
    }

    public function trustLedger(Firm $firm, TrustAccount $account, \DateTimeInterface $periodStart, \DateTimeInterface $periodEnd): AccountingReport
    {
        $entries = (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $account, $periodStart, $periodEnd) {
            $ledgerIds = $account->ledgers()->pluck('id');

            return TrustLedgerEntry::query()
                ->where('firm_id', $firm->id)
                ->whereIn('trust_ledger_id', $ledgerIds)
                ->whereBetween('posted_at', [$periodStart, $periodEnd])
                ->orderBy('posted_at')
                ->get();
        });

        return new AccountingReport($firm->id, 'trust_ledger', $periodStart, $periodEnd, $entries, now());
    }

    /**
     * @return AccountingReport data: Collection<int, array{client: \App\Models\Client, unearned_cents: int}>
     */
    public function clientTrustBalances(Firm $firm): AccountingReport
    {
        $rows = (new TenantContextService)->runWithFirmContext($firm, function () use ($firm) {
            return $firm->clients()->get()->map(fn ($client) => [
                'client' => $client,
                'unearned_cents' => $this->trustBalance->clientBalanceCents($firm, $client),
            ])->filter(fn (array $row) => $row['unearned_cents'] !== 0)->values();
        });

        return new AccountingReport($firm->id, 'client_trust_balances', null, null, $rows, now());
    }

    /**
     * @return AccountingReport data: Collection<int, array{matter_id: int, balance_cents: int}>
     */
    public function matterTrustBalances(Firm $firm): AccountingReport
    {
        $rows = (new TenantContextService)->runWithFirmContext($firm, fn () => \App\Models\MatterTrustBalance::query()
            ->where('firm_id', $firm->id)
            ->selectRaw('matter_id, sum(balance_cents) as balance_cents')
            ->groupBy('matter_id')
            ->having('balance_cents', '!=', 0)
            ->get());

        return new AccountingReport($firm->id, 'matter_trust_balances', null, null, $rows, now());
    }

    /**
     * Accounts Receivable Aging — genuinely new computation: buckets
     * every not-fully-paid invoice's remaining balance by days
     * overdue relative to due_at.
     *
     * @return AccountingReport data: Collection<int, array{invoice: \App\Models\Invoice, remaining_cents: int, days_overdue: int, bucket: string}>
     */
    public function accountsReceivableAging(Firm $firm, ?\DateTimeInterface $asOf = null): AccountingReport
    {
        $asOf = $asOf ?? now();

        $rows = (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $asOf) {
            return $firm->invoices()
                ->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::Approved, InvoiceStatus::PartiallyPaid])
                ->get()
                ->map(function ($invoice) use ($asOf) {
                    $remaining = $invoice->total_cents - $invoice->amount_paid_cents;
                    $daysOverdue = $invoice->due_at ? max(0, (int) $invoice->due_at->diffInDays($asOf, false)) : 0;

                    return [
                        'invoice' => $invoice,
                        'remaining_cents' => $remaining,
                        'days_overdue' => $daysOverdue,
                        'bucket' => match (true) {
                            $daysOverdue <= 0 => 'current',
                            $daysOverdue <= 30 => '1_30',
                            $daysOverdue <= 60 => '31_60',
                            $daysOverdue <= 90 => '61_90',
                            default => '90_plus',
                        },
                    ];
                })
                ->filter(fn (array $row) => $row['remaining_cents'] > 0)
                ->values();
        });

        return new AccountingReport($firm->id, 'accounts_receivable_aging', null, $asOf, $rows, now());
    }

    /**
     * Invoice/Payment reconciliation — verifies each invoice's cached
     * amount_paid_cents equals the sum of its accepted payments minus
     * any reversals against them. A row only appears when they
     * DISAGREE — the report is a list of exceptions, not a full dump.
     */
    public function invoicePaymentReconciliation(Firm $firm): AccountingReport
    {
        $rows = (new TenantContextService)->runWithFirmContext($firm, function () use ($firm) {
            return $firm->invoices()->get()->map(function ($invoice) {
                $paymentsTotal = (int) $invoice->payments()->where('status', \App\Enums\PaymentStatus::Succeeded)->sum('amount_cents');
                $reversalsTotal = (int) PaymentReversal::query()->where('invoice_id', $invoice->id)->sum('amount_cents');
                $expected = $paymentsTotal - $reversalsTotal;

                return [
                    'invoice' => $invoice,
                    'cached_amount_paid_cents' => $invoice->amount_paid_cents,
                    'expected_amount_paid_cents' => $expected,
                    'discrepancy_cents' => $invoice->amount_paid_cents - $expected,
                ];
            })->filter(fn (array $row) => $row['discrepancy_cents'] !== 0)->values();
        });

        return new AccountingReport($firm->id, 'invoice_payment_reconciliation', null, null, $rows, now());
    }

    /**
     * @return AccountingReport data: Collection<int, array{client: \App\Models\Client, unearned_cents: int, earned_cents: int}>
     */
    public function earnedVsUnearned(Firm $firm): AccountingReport
    {
        $rows = (new TenantContextService)->runWithFirmContext($firm, function () use ($firm) {
            return $firm->clients()->get()->map(fn ($client) => [
                'client' => $client,
                'unearned_cents' => $this->earnedFee->unearnedBalanceCentsForClient($firm, $client),
                'earned_cents' => $this->earnedFee->earnedFeesCentsForClient($firm, $client),
            ])->filter(fn (array $row) => $row['unearned_cents'] !== 0 || $row['earned_cents'] !== 0)->values();
        });

        return new AccountingReport($firm->id, 'earned_vs_unearned', null, null, $rows, now());
    }

    public function paymentAllocationActivity(Firm $firm, \DateTimeInterface $periodStart, \DateTimeInterface $periodEnd): AccountingReport
    {
        $rows = (new TenantContextService)->runWithFirmContext($firm, fn () => PaymentAllocation::query()
            ->where('firm_id', $firm->id)
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->with(['payment', 'invoice', 'paymentPlanInstallment'])
            ->get());

        return new AccountingReport($firm->id, 'payment_allocation_activity', $periodStart, $periodEnd, $rows, now());
    }

    /**
     * @return AccountingReport data: array{refunds: Collection, chargebacks: Collection, write_offs: Collection}
     */
    public function refundWriteOffChargebackActivity(Firm $firm, \DateTimeInterface $periodStart, \DateTimeInterface $periodEnd): AccountingReport
    {
        $data = (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $periodStart, $periodEnd) {
            $reversals = PaymentReversal::query()
                ->where('firm_id', $firm->id)
                ->whereBetween('created_at', [$periodStart, $periodEnd])
                ->get()
                ->groupBy(fn (PaymentReversal $reversal) => $reversal->reversal_type->value);

            return [
                'refunds' => $reversals->get('refund', new Collection),
                'chargebacks' => $reversals->get('chargeback', new Collection),
                'write_offs' => InvoiceWriteOff::query()
                    ->where('firm_id', $firm->id)
                    ->whereBetween('created_at', [$periodStart, $periodEnd])
                    ->get(),
            ];
        });

        return new AccountingReport($firm->id, 'refund_write_off_chargeback_activity', $periodStart, $periodEnd, $data, now());
    }

    /**
     * @return AccountingReport data: Collection<int, \App\ValueObjects\OperatingBankMatchResult>
     */
    public function bankReconciliationSummary(Firm $firm, Collection $transactions, OperatingLedgerBankMatchingService $matcher): AccountingReport
    {
        $results = (new TenantContextService)->runWithFirmContext($firm, fn () => $matcher->matchTransactions($firm, $transactions));

        return new AccountingReport($firm->id, 'bank_reconciliation_summary', null, null, $results, now());
    }

    public function threeWayReconciliationHistory(Firm $firm, TrustAccount $account): AccountingReport
    {
        $rows = (new TenantContextService)->runWithFirmContext($firm, fn () => TrustReconciliation::query()
            ->where('firm_id', $firm->id)
            ->where('trust_account_id', $account->id)
            ->orderByDesc('period_end')
            ->get());

        return new AccountingReport($firm->id, 'three_way_reconciliation_history', null, null, $rows, now());
    }

    public function reconciliationExceptions(Firm $firm): AccountingReport
    {
        $rows = (new TenantContextService)->runWithFirmContext($firm, fn () => TrustReconciliation::query()
            ->where('firm_id', $firm->id)
            ->where('status', TrustReconciliationStatus::Discrepancy)
            ->orderByDesc('completed_at')
            ->get());

        return new AccountingReport($firm->id, 'reconciliation_exceptions', null, null, $rows, now());
    }

    /**
     * @return AccountingReport data: Collection<int, array{source_type: string, total_debit_cents: int, total_credit_cents: int, entry_count: int}>
     */
    public function accountingActivityByPeriod(Firm $firm, \DateTimeInterface $periodStart, \DateTimeInterface $periodEnd): AccountingReport
    {
        $rows = (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $periodStart, $periodEnd) {
            return AccountingJournalEntry::query()
                ->where('firm_id', $firm->id)
                ->whereBetween('entry_date', [$periodStart, $periodEnd])
                ->with('postings')
                ->get()
                ->groupBy(fn (AccountingJournalEntry $entry) => $entry->source_type->value)
                ->map(fn (Collection $entries, string $sourceType) => [
                    'source_type' => $sourceType,
                    'total_debit_cents' => $entries->flatMap(fn ($entry) => $entry->postings)->sum('debit_cents'),
                    'total_credit_cents' => $entries->flatMap(fn ($entry) => $entry->postings)->sum('credit_cents'),
                    'entry_count' => $entries->count(),
                ])
                ->values();
        });

        return new AccountingReport($firm->id, 'accounting_activity_by_period', $periodStart, $periodEnd, $rows, now());
    }
}
