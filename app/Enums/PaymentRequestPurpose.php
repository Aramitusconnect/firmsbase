<?php

namespace App\Enums;

/**
 * PaymentRequestPurpose — Payment Link / QR Routing phase. Answers WHY
 * the client is being asked to pay. Deliberately distinct from (and
 * never merged with) two other closed-enum questions this codebase
 * already answers elsewhere:
 *   - PaymentClassification (Operating/Trust/Blocked) answers WHERE the
 *     resulting money is allowed to go — decided exclusively by
 *     PaymentClassificationService, never by this enum or by anything
 *     a client can influence.
 *   - The accounting services (OperatingJournalRecorderService,
 *     TrustDepositService, ChartOfAccountsService) answer WHAT
 *     accounting consequence gets posted.
 *
 * A purpose does not by itself authorize a classification — see
 * PaymentRequestService::expectedClassificationFor() for the (fixed,
 * server-side-only) mapping this phase uses, and TrustDepositService/
 * ManualPaymentService for what actually happens once a payment is
 * confirmed.
 */
enum PaymentRequestPurpose: string
{
    case EarnedFee = 'earned_fee';
    case TrustDeposit = 'trust_deposit';
    case FilingCostReimbursement = 'filing_cost_reimbursement';
    case PaymentPlanInstallment = 'payment_plan_installment';

    /**
     * The payer-facing wording the public payment page renders for
     * this purpose — see the master phase spec's own required wording.
     * Trust deposit wording is deliberately explicit that the funds are
     * not yet earned; no purpose is ever labeled as an ordinary invoice
     * payment when it is actually a trust deposit.
     */
    public function payerFacingDescription(): string
    {
        return match ($this) {
            self::EarnedFee => 'Payment for earned legal fees',
            self::TrustDeposit => 'Deposit to your client trust account. These funds are not treated as earned legal fees until properly earned and transferred.',
            self::FilingCostReimbursement => 'Payment/reimbursement for filing or matter costs',
            self::PaymentPlanInstallment => 'Payment-plan installment',
        };
    }
}
