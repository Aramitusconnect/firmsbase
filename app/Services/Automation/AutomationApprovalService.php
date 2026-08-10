<?php

namespace App\Services\Automation;

use App\Enums\AutomationActionExecutionStatus;
use App\Enums\AutomationApprovalStatus;
use App\Enums\FirmUserRole;
use App\Models\AutomationActionExecution;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;

/**
 * AutomationApprovalService — Event-Driven Automation Engine, item 7.
 * The ONLY place a RequiresReview AutomationActionExecution is
 * approved or rejected — "automation may not approve itself," so this
 * is invoked exclusively by a real human via the Firm UI, never by any
 * automation code path.
 *
 * Gated to FirmOwner only — no existing precedent names who should
 * approve an automated high-risk action, so this is a fresh, deliberate
 * policy choice (highest-trust role in FirmUserRole, matching how
 * genuinely consequential the master prompt frames these decisions as),
 * not a reuse of an established ceiling the way canResolvePaymentAllocation()
 * reused RECORD_PAYMENT_ROLES in an earlier pass. No AutomationActionType
 * registered in this pass is actually classified RequiresApproval (see
 * AutomationActionRiskLevel's own docblock) — this service exists as
 * real, tested infrastructure for the moment one is.
 */
class AutomationApprovalService
{
    public function approve(Firm $firm, AutomationActionExecution $actionExecution, FirmUser $approvedBy, ?string $notes = null): AutomationActionExecution
    {
        $this->assertAuthorized($firm, $actionExecution, $approvedBy);

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($actionExecution, $approvedBy, $notes) {
            return DB::transaction(function () use ($actionExecution, $approvedBy, $notes) {
                $locked = AutomationActionExecution::query()->whereKey($actionExecution->id)->lockForUpdate()->firstOrFail();

                if (! $locked->isAwaitingApproval()) {
                    throw new \RuntimeException('This action is not awaiting approval.');
                }

                $locked->update([
                    'approval_status' => AutomationApprovalStatus::Approved,
                    'approved_by_firm_user_id' => $approvedBy->id,
                    'approved_at' => now(),
                    'last_error' => $notes,
                    // Released back to the claim pool — the very next
                    // AutomationActionDispatchJob tick picks it up and
                    // runs the handler exactly as it would any other
                    // Pending row.
                    'status' => AutomationActionExecutionStatus::Pending,
                ]);

                return $locked->fresh();
            });
        });
    }

    public function reject(Firm $firm, AutomationActionExecution $actionExecution, FirmUser $rejectedBy, string $reason): AutomationActionExecution
    {
        $this->assertAuthorized($firm, $actionExecution, $rejectedBy);

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($actionExecution, $rejectedBy, $reason) {
            return DB::transaction(function () use ($actionExecution, $rejectedBy, $reason) {
                $locked = AutomationActionExecution::query()->whereKey($actionExecution->id)->lockForUpdate()->firstOrFail();

                if (! $locked->isAwaitingApproval()) {
                    throw new \RuntimeException('This action is not awaiting approval.');
                }

                $locked->update([
                    'approval_status' => AutomationApprovalStatus::Rejected,
                    'approved_by_firm_user_id' => $rejectedBy->id,
                    'approved_at' => now(),
                    'last_error' => $reason,
                    // Terminal — a rejected action is never retried,
                    // never re-submitted for approval automatically.
                    'status' => AutomationActionExecutionStatus::Failed,
                    'completed_at' => now(),
                ]);

                return $locked->fresh();
            });
        });
    }

    private function assertAuthorized(Firm $firm, AutomationActionExecution $actionExecution, FirmUser $actor): void
    {
        if ($actor->role !== FirmUserRole::FirmOwner) {
            throw new \RuntimeException('Only a Firm Owner may approve or reject an automation action.');
        }

        if ((int) $actionExecution->firm_id !== (int) $firm->id || (int) $actor->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This action execution does not belong to this firm.');
        }
    }
}
