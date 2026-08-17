<?php

declare(strict_types=1);

namespace App\Services\Pay;

use App\Enums\AccountingJournalSourceType;
use App\Enums\ChartOfAccountPurpose;
use App\Enums\PaymentAttemptState;
use App\Models\AccountingJournalEntry;
use App\Models\Firm;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\AccountingJournalPostingService;
use App\Services\ChartOfAccountsService;

/**
 * ProviderPaymentJournalRecorderService — FirmsVault Pay Gate A2
 * (v1.4 §29-§34).
 *
 * ============================================================
 * THE ONE ACCOUNTING FACT THIS CLASS EXISTS TO GET RIGHT
 * ============================================================
 *     A CARD CAPTURE IS NOT BANK CASH.
 *
 * The existing OperatingJournalRecorderService::recordFeeEarned()
 * debits ChartOfAccountPurpose::OperatingCash at the moment a payment
 * is applied. For cash, cheque and direct bank transfer that is
 * correct and is left COMPLETELY UNTOUCHED by this gate (v1.4 §32) —
 * this class adds a parallel path, it does not modify or wrap the
 * existing one. For a payment provider it would be wrong: a capture is
 * a reversible promise, net of fees, settled days later.
 *
 * So a provider capture posts:
 *
 *     Dr ProcessorClearingOperating   (gross)
 *       Cr LegalFeeRevenue            (fee portion)
 *       Cr CostReimbursementRevenue   (cost portion, when non-zero)
 *
 * and OperatingCash is not touched at all. It is reached only later,
 * via ProviderSettlementReceivable and real bank evidence — see
 * ChartOfAccountPurpose::ProviderSettlementReceivable's docblock for
 * the full three-step chain. Steps 2 and 3 are deliberately NOT
 * implemented in Gate A2: settlement ingestion is later-gate work, and
 * posting a settlement entry before settlement evidence exists would be
 * inventing an economic event.
 * ============================================================
 *
 * NO ACCOUNTS RECEIVABLE (v1.4 §29). The repository recognizes revenue
 * at cash receipt and posts no AR at invoice issuance. This class
 * preserves that basis exactly: the credit legs are the SAME revenue
 * accounts the existing recorder credits, at the same moment
 * (money received), so legal revenue is recognized ONCE and never
 * duplicated. Billing's accounting basis is not redesigned.
 *
 * NO SECOND LEDGER (v1.4 §33). Every posting goes through the existing
 * AccountingJournalPostingService::post(), which supplies balanced-
 * journal validation, same-firm consistency and the partial unique
 * index on (firm_id, idempotency_key). This class only decides WHICH
 * accounts a provider capture touches.
 *
 * TRUST (v1.4 §20). No trust account appears anywhere here, and no
 * trust-destined value can reach this method: PaymentAttemptService
 * refuses to open an attempt for any intent carrying a trust
 * allocation. Processor fees, when they are eventually posted at
 * settlement, are drawn from an OPERATING clearing balance that only
 * ever received Operating-destined value — trust principal can never
 * fund them.
 */
class ProviderPaymentJournalRecorderService
{
    public function __construct(
        private readonly ChartOfAccountsService $chartOfAccounts,
        private readonly AccountingJournalPostingService $posting,
        private readonly AccountingEntitlementPolicyService $entitlementPolicy,
    ) {}

    /**
     * Post the capture of a provider payment.
     *
     * Returns null — a documented NOT APPLICABLE, never a silently
     * dropped event — when the firm has never enabled the accounting
     * module, exactly mirroring OperatingJournalRecorderService's own
     * entitlement gate. Otherwise it posts atomically or throws
     * AccountingSetupIncompleteException, which rolls the caller's whole
     * transaction back.
     *
     * @param  int  $feeCents  the portion recognized as legal fee revenue
     * @param  int  $costCents  the portion recognized as cost reimbursement revenue
     */
    public function recordProviderCapture(
        Firm $firm,
        PaymentAttempt $attempt,
        int $feeCents,
        int $costCents,
    ): ?AccountingJournalEntry {
        if ($attempt->state !== PaymentAttemptState::Captured) {
            throw new \LogicException(
                'Refusing to post a provider capture for payment attempt ['.$attempt->id.'] in state ['
                .$attempt->state->value.']: only a captured attempt represents money the provider '
                .'actually took.'
            );
        }

        if ($feeCents + $costCents !== (int) $attempt->amount_cents) {
            throw new \LogicException(
                'Provider capture posting for attempt ['.$attempt->id.'] is unbalanced by construction: '
                .'fee '.$feeCents.' + cost '.$costCents.' does not equal the captured amount '
                .$attempt->amount_cents.'.'
            );
        }

        if (! $this->entitlementPolicy->isExpensesEnabledForFirm($firm)) {
            return null;
        }

        // The cash-side leg. NOT OperatingCash — this is the entire
        // point of this class.
        $clearing = $this->chartOfAccounts->requireByPurpose($firm, ChartOfAccountPurpose::ProcessorClearingOperating);

        $postings = [
            [
                'chart_of_account_id' => $clearing->id,
                'debit_cents' => (int) $attempt->amount_cents,
                'credit_cents' => 0,
                'client_id' => $attempt->paymentIntent?->client_id,
                'matter_id' => $attempt->paymentIntent?->matter_id,
            ],
        ];

        if ($feeCents > 0) {
            $feeRevenue = $this->chartOfAccounts->requireByPurpose($firm, ChartOfAccountPurpose::LegalFeeRevenue);
            $postings[] = [
                'chart_of_account_id' => $feeRevenue->id,
                'debit_cents' => 0,
                'credit_cents' => $feeCents,
                'client_id' => $attempt->paymentIntent?->client_id,
                'matter_id' => $attempt->paymentIntent?->matter_id,
            ];
        }

        if ($costCents > 0) {
            $costRevenue = $this->chartOfAccounts->requireByPurpose($firm, ChartOfAccountPurpose::CostReimbursementRevenue);
            $postings[] = [
                'chart_of_account_id' => $costRevenue->id,
                'debit_cents' => 0,
                'credit_cents' => $costCents,
                'client_id' => $attempt->paymentIntent?->client_id,
                'matter_id' => $attempt->paymentIntent?->matter_id,
            ];
        }

        return $this->posting->post(
            $firm,
            AccountingJournalSourceType::ProviderPaymentCaptured,
            'Provider payment captured — attempt #'.$attempt->id,
            now(),
            $postings,
            ['payment_attempt_id' => $attempt->id, 'invoice_id' => $attempt->paymentIntent?->invoice_id],
            // Deterministic and attempt-scoped: a replayed capture
            // cannot post twice (FV-A2-045). Rides the existing partial
            // unique index on (firm_id, idempotency_key).
            idempotencyKey: 'provider_payment_captured:payment_attempt:'.$attempt->id,
        );
    }

    /**
     * Post a SUCCESSFUL provider refund. The exact mirror of the capture
     * posting, before settlement: the money leaves the processor
     * clearing balance, and previously recognized fee revenue is
     * reversed. OperatingCash is — again — never touched (v1.4 §38).
     *
     *     Dr LegalFeeRevenue              (refunded amount)
     *       Cr ProcessorClearingOperating (refunded amount)
     *
     * POC simplification, documented: Gate A2/A3 captures recognize the
     * full amount as fee revenue, so the reversal is fee-side only;
     * mixed fee/cost refund composition is later-gate work. Exactly-once
     * by the journal's partial UNIQUE (firm_id, idempotency_key) with a
     * deterministic per-refund key.
     */
    public function recordProviderRefund(
        Firm $firm,
        PaymentRefund $refund,
    ): ?AccountingJournalEntry {
        if (! $this->entitlementPolicy->isExpensesEnabledForFirm($firm)) {
            return null;
        }

        $clearing = $this->chartOfAccounts->requireByPurpose($firm, ChartOfAccountPurpose::ProcessorClearingOperating);
        $feeRevenue = $this->chartOfAccounts->requireByPurpose($firm, ChartOfAccountPurpose::LegalFeeRevenue);

        return $this->posting->post(
            $firm,
            AccountingJournalSourceType::Refund,
            'Provider refund succeeded — refund #'.$refund->id,
            now(),
            [
                ['chart_of_account_id' => $feeRevenue->id, 'debit_cents' => (int) $refund->amount_cents, 'credit_cents' => 0],
                ['chart_of_account_id' => $clearing->id, 'debit_cents' => 0, 'credit_cents' => (int) $refund->amount_cents],
            ],
            ['payment_attempt_id' => $refund->payment_attempt_id],
            idempotencyKey: 'provider_refund_succeeded:payment_refund:'.$refund->id,
        );
    }
}
