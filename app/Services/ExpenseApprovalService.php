<?php

namespace App\Services;

use App\Enums\ExpenseApprovalStatus;
use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseApproval;
use App\Models\Firm;
use App\Models\FirmUser;
use App\ValueObjects\ExpenseApprovalDecision;

/**
 * ExpenseApprovalService — the only writer of expense_approvals and
 * the only service that may move expenses.status to Approved/Rejected.
 * decide()/recordDecision() split mirrors Phase 3's
 * PaymentClassificationService exactly: pure decision logic separated
 * from persistence. Approver role set is fixed to FirmOwner/BillingStaff
 * (correction #5) — enforced via
 * AccountingEntitlementPolicyService::assertCanApprove().
 */
class ExpenseApprovalService
{
    public function __construct(
        private readonly AccountingEntitlementPolicyService $entitlementPolicy,
        private readonly TenantSafeAccountingPolicyService $tenantSafePolicy,
    ) {
    }

    public function decide(Expense $expense, bool $approve, ?string $reason = null): ExpenseApprovalDecision
    {
        if (! in_array($expense->status, [ExpenseStatus::Submitted, ExpenseStatus::Rejected], true)) {
            return new ExpenseApprovalDecision(
                status: ExpenseApprovalStatus::Rejected,
                accepted: false,
                reason: 'Only a submitted (or previously rejected, resubmitted) expense may be approved or rejected.',
            );
        }

        return new ExpenseApprovalDecision(
            status: $approve ? ExpenseApprovalStatus::Approved : ExpenseApprovalStatus::Rejected,
            accepted: true,
            reason: $reason,
        );
    }

    public function recordDecision(
        Firm $firm,
        Expense $expense,
        FirmUser $approver,
        ExpenseApprovalDecision $decision,
    ): ExpenseApproval {
        $this->entitlementPolicy->assertExpensesEnabled($firm);
        $this->tenantSafePolicy->assertExpenseBelongsToFirm($expense, $firm);
        $this->entitlementPolicy->assertCanApprove($approver);

        if (! $decision->accepted) {
            throw new \RuntimeException($decision->reason ?? 'Expense approval decision was not accepted.');
        }

        $approval = ExpenseApproval::create([
            'firm_id' => $firm->id,
            'expense_id' => $expense->id,
            'status' => $decision->status,
            'decided_by_firm_user_id' => $approver->id,
            'decided_at' => now(),
            'reason' => $decision->reason,
        ]);

        $expense->update([
            'status' => $decision->status === ExpenseApprovalStatus::Approved
                ? ExpenseStatus::Approved
                : ExpenseStatus::Rejected,
        ]);

        return $approval;
    }

    /**
     * Convenience wrappers combining decide()+recordDecision() for the
     * common case.
     */
    public function approve(Firm $firm, Expense $expense, FirmUser $approver, ?string $reason = null): ExpenseApproval
    {
        return $this->recordDecision($firm, $expense, $approver, $this->decide($expense, true, $reason));
    }

    public function reject(Firm $firm, Expense $expense, FirmUser $approver, string $reason): ExpenseApproval
    {
        return $this->recordDecision($firm, $expense, $approver, $this->decide($expense, false, $reason));
    }
}
