<?php

namespace App\Services;

use App\Enums\TrustApprovalEventType;
use App\Enums\TrustLedgerEntryType;
use App\Enums\TrustRefundRequestStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\TrustApprovalEvent;
use App\Models\TrustLedger;
use App\Models\TrustLedgerEntry;
use App\Models\TrustRefundRequest;
use Illuminate\Support\Str;

/**
 * TrustRefundRequestService — request/approve/deny/complete a trust
 * refund (money returned to the client, outside the firm's own
 * invoicing — no Payment/invoice integration, unlike a transfer).
 * complete() posts a Refund-type entry inside one locked transaction;
 * the original deposit entry this refund draws against is never
 * mutated.
 */
class TrustRefundRequestService
{
    public function __construct(
        private readonly TrustEligibilityService $eligibility,
        private readonly TrustAccessPolicyService $accessPolicy,
        private readonly TenantSafeTrustPolicyService $tenantSafePolicy,
        private readonly TrustCrossMatterProtectionService $crossMatterProtection,
        private readonly TrustConcurrencyLockService $lockService,
        private readonly TrustBalanceService $balanceService,
    ) {
    }

    public function requestRefund(
        Firm $firm,
        TrustLedger $ledger,
        FirmUser $requestedBy,
        int $amountCents,
        ?Matter $matter = null,
    ): TrustRefundRequest {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustLedgerBelongsToFirm($ledger, $firm);

        if ($matter) {
            $this->crossMatterProtection->assertMatterEligibleForLedger($matter, $ledger);
        }

        $this->accessPolicy->assertCanRequest($requestedBy);

        if ($amountCents <= 0) {
            throw new \RuntimeException('Refund amount must be positive.');
        }

        $request = TrustRefundRequest::create([
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'matter_id' => $matter?->id,
            'amount_cents' => $amountCents,
            'status' => TrustRefundRequestStatus::Requested,
            'requested_by_firm_user_id' => $requestedBy->id,
        ]);

        $this->recordEvent($firm, $request, TrustApprovalEventType::RefundRequested, $requestedBy, $amountCents, $matter?->id);

        return $request;
    }

    public function approveRefund(Firm $firm, TrustRefundRequest $request, FirmUser $approvedBy): TrustRefundRequest
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustRefundRequestBelongsToFirm($request, $firm);
        $this->accessPolicy->assertCanApprove($approvedBy);

        if (! in_array($request->status, [TrustRefundRequestStatus::Requested, TrustRefundRequestStatus::PendingApproval], true)) {
            throw new \RuntimeException('This refund request is not awaiting approval.');
        }

        $request->update([
            'status' => TrustRefundRequestStatus::Approved,
            'approved_by_firm_user_id' => $approvedBy->id,
        ]);

        $this->recordEvent($firm, $request, TrustApprovalEventType::RefundApproved, $approvedBy, $request->amount_cents, $request->matter_id);

        return $request->fresh();
    }

    public function denyRefund(Firm $firm, TrustRefundRequest $request, FirmUser $deniedBy, string $reason): TrustRefundRequest
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustRefundRequestBelongsToFirm($request, $firm);
        $this->accessPolicy->assertCanApprove($deniedBy);

        if (! in_array($request->status, [TrustRefundRequestStatus::Requested, TrustRefundRequestStatus::PendingApproval], true)) {
            throw new \RuntimeException('This refund request is not awaiting approval.');
        }

        $request->update([
            'status' => TrustRefundRequestStatus::Denied,
            'denied_reason' => $reason,
        ]);

        $this->recordEvent($firm, $request, TrustApprovalEventType::RefundDenied, $deniedBy, $request->amount_cents, $request->matter_id);

        return $request->fresh();
    }

    public function complete(Firm $firm, TrustRefundRequest $request, FirmUser $completedBy): TrustLedgerEntry
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustRefundRequestBelongsToFirm($request, $firm);
        $this->accessPolicy->assertCanApprove($completedBy);

        if ($request->status !== TrustRefundRequestStatus::Approved) {
            throw new \RuntimeException('Only an Approved refund request can be completed.');
        }

        // Section 39A-3L, Checkpoint 4 - matters is already a FORCE-RLS
        // table from an earlier checkpoint (trust_ledgers is not yet
        // RLS-enabled at all). These two reads used to work only by
        // accident, relying on ambient database session context left
        // active by MatterFactory's context-hold create() pattern
        // earlier in the caller's flow. EntitlementService::resolve()
        // now correctly clears any such ambient context when the
        // eligibility check above returns, so these two reads are
        // combined into one explicit whole-call wrap here rather than
        // left unwrapped. This matters even more than in the transfer/
        // adjustment flows: with $matter silently null, the
        // `if ($matter)` gate below would silently SKIP the real
        // cross-matter safety check instead of failing closed.
        [$ledger, $matter] = (new TenantContextService())->runWithFirmContext($firm, fn () => [
            $request->trustLedger,
            $request->matter,
        ]);

        $this->tenantSafePolicy->assertTrustLedgerBelongsToFirm($ledger, $firm);

        if ($matter) {
            $this->crossMatterProtection->assertMatterEligibleForLedger($matter, $ledger);
        }

        $amountCents = $request->amount_cents;

        $entry = $this->lockService->withLockedBalances($ledger, $matter, function ($lockedBalance, $lockedMatterBalance) use (
            $firm, $ledger, $matter, $request, $amountCents
        ) {
            if ($lockedBalance->balance_cents < $amountCents) {
                throw new \RuntimeException('Trust ledger balance is insufficient for this refund.');
            }

            if ($matter) {
                $this->crossMatterProtection->assertDebitKeepsMatterBalanceNonNegative($lockedMatterBalance, -1 * $amountCents);
            }

            $entry = TrustLedgerEntry::create([
                'firm_id' => $firm->id,
                'trust_ledger_id' => $ledger->id,
                'matter_id' => $matter?->id,
                'entry_type' => TrustLedgerEntryType::Refund,
                'amount_cents' => -1 * $amountCents,
                'trust_refund_request_id' => $request->id,
                'posted_at' => now(),
            ]);

            $this->balanceService->recomputeForLedger($ledger, $lockedBalance);

            if ($matter) {
                $this->balanceService->recomputeForMatter($ledger, $matter, $lockedMatterBalance);
            }

            return $entry;
        });

        $request->update([
            'status' => TrustRefundRequestStatus::Completed,
            'completed_at' => now(),
        ]);

        $this->recordEvent($firm, $request, TrustApprovalEventType::RefundCompleted, $completedBy, $amountCents, $matter?->id);

        return $entry;
    }

    private function recordEvent(
        Firm $firm,
        TrustRefundRequest $request,
        TrustApprovalEventType $eventType,
        FirmUser $actor,
        int $amountCents,
        ?int $matterId,
    ): void {
        TrustApprovalEvent::create([
            'firm_id' => $firm->id,
            'event_type' => $eventType,
            'actor_firm_user_id' => $actor->id,
            'amount_cents' => $amountCents,
            'matter_id' => $matterId,
            'approved_entry_type' => TrustLedgerEntryType::Refund->value,
            'correlation_uuid' => (string) Str::uuid7(),
            'trust_ledger_id' => $request->trust_ledger_id,
            'trust_refund_request_id' => $request->id,
        ]);
    }
}
