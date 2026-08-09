<?php

namespace App\Services;

use App\Enums\ChartOfAccountType;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Matter;
use Illuminate\Support\Facades\DB;

/**
 * AccountingBalanceService — read-only. Every balance is derived by
 * summing accounting_postings, never read from a cached/mutable
 * running-balance field (none exists — deliberate, per the same
 * "derive from immutable entries" discipline TrustBalanceService
 * already follows for trust_ledger_entries). No balance is ever
 * written by this service.
 */
class AccountingBalanceService
{
    /**
     * Asset/Expense accounts have a normal DEBIT balance (increase =
     * debit); Liability/Equity/Revenue accounts have a normal CREDIT
     * balance (increase = credit). This mapping is a pure function,
     * never redundantly stored on the account row itself.
     */
    private function isDebitNormal(ChartOfAccountType $type): bool
    {
        return in_array($type, [ChartOfAccountType::Asset, ChartOfAccountType::Expense], true);
    }

    public function accountBalanceCents(Firm $firm, ChartOfAccount $account): int
    {
        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $account) {
            $sums = DB::table('accounting_postings')
                ->where('firm_id', $firm->id)
                ->where('chart_of_account_id', $account->id)
                ->selectRaw('COALESCE(SUM(debit_cents), 0) as total_debit, COALESCE(SUM(credit_cents), 0) as total_credit')
                ->first();

            $totalDebit = (int) $sums->total_debit;
            $totalCredit = (int) $sums->total_credit;

            return $this->isDebitNormal($account->account_type)
                ? $totalDebit - $totalCredit
                : $totalCredit - $totalDebit;
        });
    }

    public function clientBalanceCents(Firm $firm, ChartOfAccount $account, Client $client): int
    {
        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $account, $client) {
            $sums = DB::table('accounting_postings')
                ->where('firm_id', $firm->id)
                ->where('chart_of_account_id', $account->id)
                ->where('client_id', $client->id)
                ->selectRaw('COALESCE(SUM(debit_cents), 0) as total_debit, COALESCE(SUM(credit_cents), 0) as total_credit')
                ->first();

            $totalDebit = (int) $sums->total_debit;
            $totalCredit = (int) $sums->total_credit;

            return $this->isDebitNormal($account->account_type)
                ? $totalDebit - $totalCredit
                : $totalCredit - $totalDebit;
        });
    }

    public function matterBalanceCents(Firm $firm, ChartOfAccount $account, Matter $matter): int
    {
        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $account, $matter) {
            $sums = DB::table('accounting_postings')
                ->where('firm_id', $firm->id)
                ->where('chart_of_account_id', $account->id)
                ->where('matter_id', $matter->id)
                ->selectRaw('COALESCE(SUM(debit_cents), 0) as total_debit, COALESCE(SUM(credit_cents), 0) as total_credit')
                ->first();

            $totalDebit = (int) $sums->total_debit;
            $totalCredit = (int) $sums->total_credit;

            return $this->isDebitNormal($account->account_type)
                ? $totalDebit - $totalCredit
                : $totalCredit - $totalDebit;
        });
    }

    /**
     * Phase E — "useful client/matter accounting views derived from
     * journal postings": a per-account breakdown across every account
     * the client/matter has ANY postings against, rather than making
     * every caller already know which single account to ask about.
     * Still derived live from accounting_postings every call — no
     * cache, nothing new to keep in sync.
     *
     * @return array<int, array{account: ChartOfAccount, balance_cents: int}>
     */
    public function accountBalancesForClient(Firm $firm, Client $client): array
    {
        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $client) {
            $accountIds = DB::table('accounting_postings')
                ->where('firm_id', $firm->id)
                ->where('client_id', $client->id)
                ->distinct()
                ->pluck('chart_of_account_id');

            return ChartOfAccount::query()
                ->whereIn('id', $accountIds)
                ->get()
                ->map(fn (ChartOfAccount $account) => [
                    'account' => $account,
                    'balance_cents' => $this->clientBalanceCents($firm, $account, $client),
                ])
                ->all();
        });
    }

    /**
     * @return array<int, array{account: ChartOfAccount, balance_cents: int}>
     */
    public function accountBalancesForMatter(Firm $firm, Matter $matter): array
    {
        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $matter) {
            $accountIds = DB::table('accounting_postings')
                ->where('firm_id', $firm->id)
                ->where('matter_id', $matter->id)
                ->distinct()
                ->pluck('chart_of_account_id');

            return ChartOfAccount::query()
                ->whereIn('id', $accountIds)
                ->get()
                ->map(fn (ChartOfAccount $account) => [
                    'account' => $account,
                    'balance_cents' => $this->matterBalanceCents($firm, $account, $matter),
                ])
                ->all();
        });
    }
}
