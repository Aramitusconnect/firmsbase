<?php

namespace App\Services;

use App\Enums\ChartOfAccountPurpose;
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
 *
 * ============================================================
 * ACCOUNTING INTEGRITY HARDENING PASS, item 4 — the explicit
 * earned/unearned recognition boundary, re-audited (not assumed) event
 * by event. This is a documentation clarification of an existing,
 * already-correct boundary, not a new classification system — it does
 * NOT replace or duplicate PaymentClassificationService (Operating /
 * Trust / Blocked — "which bucket does this cash belong in" — a
 * completely different question this service never answers).
 *
 *   - MONEY RECEIVED as a Trust deposit: posts nothing here or on the
 *     operating books at all. Unearned, by definition, the instant it
 *     lands (TrustLedgerEntryType::Deposit).
 *   - MONEY HELD IN TRUST: unearnedBalanceCentsForClient/Matter() above
 *     — always TrustBalanceService's live trust_balances/
 *     matter_trust_balances, never a second cache.
 *   - INVOICE ISSUED: recognizes NOTHING by itself. Issuing an invoice
 *     is a billing event, not a cash event — no journal entry exists
 *     for it, on purpose (see OperatingJournalRecorderService's own
 *     revenue-recognition-model docblock).
 *   - PAYMENT APPLIED directly (no trust involved) against an
 *     already-issued, already-sent invoice: THIS is a legitimate
 *     earning event — the underlying legal work was already billed
 *     (the invoice already exists and was already sent), so cash
 *     actually landing for it is the objective, unambiguous moment the
 *     fee becomes the firm's revenue. Posts InvoicePaymentApplied.
 *   - TRUST TRANSFER APPROVED (TrustTransferRequestStatus::Approved):
 *     recognizes NOTHING by itself — approval is authorization to move
 *     money, not the movement itself. No journal entry exists for this
 *     status transition.
 *   - TRUST TRANSFER APPLIED (TrustTransferRequestService::apply()):
 *     THIS is the earning event for money that started in trust — it
 *     is the one explicit, human-authorized act ("apply this retainer
 *     against this invoice") that converts a client's still-owned trust
 *     funds into the firm's own revenue. Posts TrustToOperatingTransfer.
 *     Before this moment, the same dollars are unearned
 *     (unearnedBalanceCentsForClient/Matter()); after it, they are
 *     earned (earnedFeesCentsForClient/Matter()) — never both, never
 *     neither, and the transfer itself is the only door between the two.
 *   - FEE EARNED / REVENUE RECOGNIZED: the two are the same event in
 *     this codebase — "earned" and "recognized as revenue" both mean
 *     exactly "OperatingJournalRecorderService posted a Revenue-purpose
 *     credit," never merely "cash was received" (a trust deposit alone
 *     recognizes nothing) and never merely "an invoice was sent" (no
 *     accrual entry exists in this model).
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
     * the firm has not set up an active LegalFeeRevenue-purpose account
     * yet. This method only ever COMPUTES a figure — it never gates a
     * business transaction — so it stays gracefully nullable-to-zero
     * even after the Accounting Integrity Hardening Pass made
     * OperatingJournalRecorderService's own resolution hard-fail
     * instead (see that class's own docblock): a missing Revenue
     * account here means "$0 earned so far," a true and useful answer,
     * not a silently-dropped financial event.
     */
    public function earnedFeesCentsForClient(Firm $firm, Client $client): int
    {
        $revenue = $this->chartOfAccounts->resolveByPurpose($firm, ChartOfAccountPurpose::LegalFeeRevenue);

        return $revenue === null ? 0 : $this->accountingBalance->clientBalanceCents($firm, $revenue, $client);
    }

    public function earnedFeesCentsForMatter(Firm $firm, Matter $matter): int
    {
        $revenue = $this->chartOfAccounts->resolveByPurpose($firm, ChartOfAccountPurpose::LegalFeeRevenue);

        return $revenue === null ? 0 : $this->accountingBalance->matterBalanceCents($firm, $revenue, $matter);
    }
}
