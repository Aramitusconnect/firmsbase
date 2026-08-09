<?php

namespace App\Enums;

/**
 * ChartOfAccountPurpose — Accounting Integrity Hardening Pass. Answers
 * "which specific posting account is this" within a ChartOfAccountType
 * ("what class of account is this"), so a posting service never has to
 * fall back to "the first Asset account" (ChartOfAccountsService::
 * resolveActiveAccountByType()'s own ordering, which was never a real
 * identity — merely the oldest row of a given type). A firm may still
 * create as many chart_of_accounts rows of a given account_type as it
 * wants (e.g. multiple bank accounts, all type=asset); purpose is what
 * lets a posting service resolve exactly ONE of them unambiguously — a
 * partial unique index on (firm_id, purpose) where purpose is not null
 * and the row is active enforces "at most one active account per
 * purpose per firm" at the database level, not merely by convention.
 *
 * OperatingCash, LegalFeeRevenue, GeneralOperatingExpense,
 * CostReimbursementRevenue, and UnappliedOperatingFundsLiability are
 * currently READ by any real posting code path
 * (OperatingJournalRecorderService, AccountingEarnedFeeService,
 * AccountingPeriodCloseService) — see each case's own docblock for
 * exactly which. The remaining cases exist as a complete, named
 * classification vocabulary a firm can assign to its own
 * chart_of_accounts rows today (for its own bookkeeping clarity, or
 * for a future QuickBooks export mapping) even though nothing in this
 * codebase posts to them yet; adding a new case later needs no
 * migration, since this column has no database-level check constraint
 * (mirrors ChartOfAccountType's own existing precedent).
 */
enum ChartOfAccountPurpose: string
{
    /**
     * The single operating cash/bank account debited when a fee is
     * earned (OperatingJournalRecorderService::recordFeeEarned()) and
     * credited when an expense is paid or cash otherwise leaves the
     * firm (recordExpensePaid(), recordCashOut() for refunds/
     * chargebacks). Also the account AccountingPeriodCloseService
     * snapshots opening/closing balances for.
     */
    case OperatingCash = 'operating_cash';

    /**
     * Not currently posted to by any service. Accounts Receivable has
     * no posting under this codebase's payment-time (cash-basis-like)
     * revenue-recognition model — see AccountingEarnedFeeService's own
     * docblock and OperatingJournalRecorderService's revenue-
     * recognition-model docblock: revenue is recognized only when cash
     * is actually received, so there is never an invoice-issuance-time
     * entry that would need an offsetting A/R debit.
     * AccountingReportingService::accountsReceivableAging() computes
     * AR straight from the Invoice model, not from a posted A/R
     * account balance. Reserved for a future accrual-basis extension.
     */
    case AccountsReceivable = 'accounts_receivable';

    /**
     * The revenue account credited when a fee is earned
     * (OperatingJournalRecorderService::recordFeeEarned()) and debited
     * when a refund/chargeback reverses previously-recognized revenue
     * (recordCashOut()). Also the account AccountingEarnedFeeService
     * reads client/matter earned-fee balances from.
     */
    case LegalFeeRevenue = 'legal_fee_revenue';

    /**
     * Not currently posted to by any service. Under Phase C's explicit
     * design decision, money sitting in trust never touches the
     * operating books at all — TrustLedgerEntry/TrustBalance remain the
     * sole source of truth for unearned/retainer funds
     * (AccountingEarnedFeeService::unearnedBalanceCentsForClient/Matter()
     * delegate to TrustBalanceService, never to any operating
     * chart-of-accounts row). This purpose exists so a firm CAN still
     * label an operating-side liability account this way for its own
     * bookkeeping or export mapping, without the journal recorder ever
     * assuming it needs one to post a real event — introducing a
     * parallel operating-side unearned-liability ledger would
     * contradict Trust's role as sole source of truth, which this
     * mission's global "do not duplicate" rule forbids.
     */
    case UnearnedFeeLiability = 'unearned_fee_liability';

    /**
     * Not currently posted to by any service. No payment processor fee
     * amount is captured anywhere in the Payment domain today (Payment
     * has no fee_cents/net_amount_cents column, and no processor
     * integration deducts one) — there is nothing yet to post here.
     * Reserved for when a real processor-fee amount becomes available.
     */
    case ProcessorFees = 'processor_fees';

    /**
     * Not currently posted to by any service, but this is the intended
     * purpose for a chart_of_accounts row an ExpenseCategory maps to
     * (ExpenseCategory::chartOfAccount()) when that category represents
     * client-billable costs (Expense.reimbursable=true — see
     * ReimbursableExpenseInvoiceEligibilityService's own docblock:
     * "reimbursable" means billable through to the client on an
     * invoice, not owed BY the firm TO an employee). Kept distinct from
     * GeneralOperatingExpense so a firm that wants to track
     * client-reimbursable costs separately from firm overhead can.
     */
    case ClientCostReimbursableExpense = 'client_cost_reimbursable_expense';

    /**
     * The general/fallback expense account
     * OperatingJournalRecorderService::recordExpensePaid() resolves
     * when the paid expense's own category has no chart_of_accounts
     * mapping (ExpenseCategory.chart_of_accounts_id is null) — the
     * category-specific account, when present, is always tried first
     * and takes priority over this purpose.
     */
    case GeneralOperatingExpense = 'general_operating_expense';

    /**
     * Mixed-Invoice Revenue Allocation pass — read by
     * OperatingJournalRecorderService::recordFeeEarned() whenever
     * PaymentApplicationService::resolveInvoiceRevenueAllocation() has
     * resolved a non-zero cost-reimbursement portion for a mixed
     * invoice (both ordinary fee lines AND
     * InvoiceLineType::ReimbursableExpense lines) — full payment,
     * purpose-constrained partial payment, or a resolved
     * PendingPaymentAllocation. A firm with reimbursable-expense
     * invoice lines but no chart_of_accounts row of this purpose will
     * have such a payment blocked atomically (AccountingSetupIncompleteException,
     * same post-or-block policy as every other required purpose) rather
     * than silently misposted as LegalFeeRevenue. A genuinely ambiguous
     * partial payment never reaches this account at all — see
     * PendingPaymentAllocation and UnappliedOperatingFundsLiability's
     * own docblocks for what happens to it instead.
     */
    case CostReimbursementRevenue = 'cost_reimbursement_revenue';

    /**
     * Not currently posted to by any service. InvoiceWriteOffService
     * deliberately posts nothing (see its own docblock and the
     * invoice_write_offs migration's docblock): under the payment-time
     * revenue-recognition model, an invoice's unpaid remainder was
     * never recognized as revenue in the first place, so a write-off
     * has no revenue to reverse. Reserved for a future accrual-basis
     * extension where a write-off would need a real bad-debt-expense
     * posting.
     */
    case WriteOffBadDebt = 'write_off_bad_debt';

    /**
     * Accounting Integrity Hardening Pass, item 8. The offsetting
     * equity leg AccountingOpeningBalanceService posts opening-balance
     * cutover entries against — the standard double-entry convention
     * for a migration cutover (debit the opening asset/AR balances,
     * credit this account for the net starting position) so a firm's
     * opening entry is genuinely balanced rather than posted against
     * an arbitrary existing account.
     */
    case OpeningBalanceEquity = 'opening_balance_equity';

    /**
     * Pending-Cash Accounting pass. A Liability-type account: cash the
     * firm has genuinely received on its OPERATING books but cannot
     * yet recognize as revenue because the fee-vs-cost split is
     * ambiguous (PaymentApplicationService::resolveInvoiceRevenueAllocation()
     * returned an ambiguous decision, so ManualPaymentService deferred
     * the payment to a PendingPaymentAllocation instead of guessing).
     * Named "Operating", not "Client", to keep this unmistakably
     * distinct from Trust/IOLTA client funds — this account never
     * represents money held in trust; TrustLedgerEntry/TrustBalance
     * remain the sole source of truth for that, completely unaffected
     * by this pass (see this class's own OperatingCash/LegalFeeRevenue
     * docblocks for the established "Operating" naming precedent).
     *
     * Posted to by OperatingJournalRecorderService::recordUnappliedFundsReceived()
     * (Dr Operating Cash / Cr this account, at the moment an ambiguous
     * payment is received — the cash itself is never left off the
     * books while allocation is pending) and reversed to zero by
     * OperatingJournalRecorderService::recordUnappliedFundsResolved()
     * (Dr this account / Cr LegalFeeRevenue and/or CostReimbursementRevenue,
     * for the exact resolved split — never a second cash debit, since
     * the cash was already recorded at receipt).
     */
    case UnappliedOperatingFundsLiability = 'unapplied_operating_funds_liability';
}
