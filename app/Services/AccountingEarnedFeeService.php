<?php

namespace App\Services;

use App\Enums\ChartOfAccountType;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Matter;

/**
 * AccountingEarnedFeeService — Phase C of the legal accounting
 * foundation: earned vs. unearned, a genuinely distinct question from
 * PaymentClassificationService (which answers Operating / Trust /
 * Blocked — "which bucket does this cash belong in"). Earned/unearned
 * answers "has the firm actually earned this money yet, or does the
 * client still own it."
 *
 * Deliberately NOT a new ledger or balance table:
 *   - "Unearned" is exactly the client's/matter's live trust balance —
 *     TrustBalanceService (extended in this same phase with
 *     clientBalanceCents()/matterBalanceCentsAggregate()) is reused
 *     unchanged as the sole source of truth. Money sitting in trust,
 *     by definition, has not yet been earned.
 *   - "Earned" is exactly the sum of Revenue postings the operating
 *     journal has recorded for that client/matter — AccountingBalanceService
 *     (Phase A) is reused unchanged as the sole source of truth. A fee
 *     only becomes earned the instant OperatingJournalRecorderService
 *     posts it (payment applied directly, or a Trust → Operating
 *     transfer lands) — never merely because cash was received (a
 *     trust deposit alone posts nothing to these operating books at
 *     all, satisfying "do not treat cash receipt itself as automatic
 *     revenue").
 *
 * This service moves no money and posts nothing — it is a pure
 * read-side composition of the two existing sources of truth. Money
 * only ever moves trust → operating through the existing, unmodified
 * TrustTransferRequestService workflow (request/approve/apply); this
 * service never auto-transfers anything.
 */
class AccountingEarnedFeeService
{
    public function __construct(
        private readonly TrustBalanceService $trustBalance,
        private readonly AccountingBalanceService $accountingBalance,
        private readonly ChartOfAccountsService $chartOfAccounts,
    ) {}

    /**
     * The client's currently-unearned retainer balance — funds the
     * client still owns, sitting in trust, not yet billed/transferred
     * out. Zero once every trust dollar has either been transferred to
     * operating (earned) or refunded back to the client.
     */
    public function unearnedBalanceCentsForClient(Firm $firm, Client $client): int
    {
        return $this->trustBalance->clientBalanceCents($firm, $client);
    }

    public function unearnedBalanceCentsForMatter(Firm $firm, Matter $matter): int
    {
        return $this->trustBalance->matterBalanceCentsAggregate($firm, $matter);
    }

    /**
     * Cumulative earned fees recognized for this client on the
     * operating books to date (all-time, not period-scoped — callers
     * needing a period window should filter accounting_postings via
     * AccountingReportingService's period reports instead, which is
     * where date-range logic belongs, not here). Zero (never null) if
     * the firm has not set up an active Revenue account yet — the same
     * graceful, opt-in posture OperatingJournalRecorderService uses.
     */
    public function earnedFeesCentsForClient(Firm $firm, Client $client): int
    {
        $revenue = $this->chartOfAccounts->resolveActiveAccountByType($firm, ChartOfAccountType::Revenue);

        return $revenue === null ? 0 : $this->accountingBalance->clientBalanceCents($firm, $revenue, $client);
    }

    public function earnedFeesCentsForMatter(Firm $firm, Matter $matter): int
    {
        $revenue = $this->chartOfAccounts->resolveActiveAccountByType($firm, ChartOfAccountType::Revenue);

        return $revenue === null ? 0 : $this->accountingBalance->matterBalanceCents($firm, $revenue, $matter);
    }
}
