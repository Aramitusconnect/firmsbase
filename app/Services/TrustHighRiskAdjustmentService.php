<?php

namespace App\Services;

use App\Enums\TrustApprovalEventType;
use App\Enums\TrustLedgerEntryType;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\TrustApprovalEvent;
use App\Models\TrustLedger;
use App\Models\TrustLedgerEntry;
use Illuminate\Support\Str;

/**
 * TrustHighRiskAdjustmentService — the only path that posts a manual
 * Adjustment-type entry (a correction outside the ordinary
 * deposit/transfer/refund/chargeback flows), gated behind TWO DIFFERENT
 * approvers, both from {FirmOwner, Attorney} (correction #6). Like
 * TrustDepositService, there is no dedicated table for the
 * request/approval lifecycle — trust_approval_events' structured
 * columns carry it, linked by a shared correlation_uuid.
 *
 * amountCentsDelta is signed: a positive adjustment credits the ledger,
 * a negative one debits it. A debit must still pass the same
 * non-negative-balance checks as every other money-moving service.
 */
class TrustHighRiskAdjustmentService
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

    public function requestAdjustment(
        Firm $firm,
        TrustLedger $ledger,
        FirmUser $requestedBy,
        int $amountCentsDelta,
        string $reason,
        ?Matter $matter = null,
    ): TrustApprovalEvent {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustLedgerBelongsToFirm($ledger, $firm);

        if ($matter) {
            $this->crossMatterProtection->assertMatterEligibleForLedger($matter, $ledger);
        }

        $this->accessPolicy->assertCanRequest($requestedBy);

        if ($amountCentsDelta === 0) {
            throw new \RuntimeException('Adjustment amount cannot be zero.');
        }

        return TrustApprovalEvent::create([
            'firm_id' => $firm->id,
            'event_type' => TrustApprovalEventType::AdjustmentRequested,
            'actor_firm_user_id' => $requestedBy->id,
            'amount_cents' => $amountCentsDelta,
            'matter_id' => $matter?->id,
            'approved_entry_type' => TrustLedgerEntryType::Adjustment->value,
            'correlation_uuid' => (string) Str::uuid7(),
            'trust_ledger_id' => $ledger->id,
            'metadata_json' => ['reason' => $reason],
        ]);
    }

    public function firstApprove(Firm $firm, TrustApprovalEvent $requestedEvent, FirmUser $firstApprover): TrustApprovalEvent
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustApprovalEventBelongsToFirm($requestedEvent, $firm);
        $this->accessPolicy->assertCanApprove($firstApprover);

        if ($requestedEvent->event_type !== TrustApprovalEventType::AdjustmentRequested) {
            throw new \RuntimeException('This event is not a pending adjustment request.');
        }

        return TrustApprovalEvent::create([
            'firm_id' => $firm->id,
            'event_type' => TrustApprovalEventType::AdjustmentFirstApproved,
            'actor_firm_user_id' => $firstApprover->id,
            'amount_cents' => $requestedEvent->amount_cents,
            'matter_id' => $requestedEvent->matter_id,
            'approved_entry_type' => TrustLedgerEntryType::Adjustment->value,
            'correlation_uuid' => $requestedEvent->correlation_uuid,
            'trust_ledger_id' => $requestedEvent->trust_ledger_id,
        ]);
    }

    public function denyAdjustment(Firm $firm, TrustApprovalEvent $event, FirmUser $deniedBy): TrustApprovalEvent
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustApprovalEventBelongsToFirm($event, $firm);
        $this->accessPolicy->assertCanApprove($deniedBy);

        if (! in_array($event->event_type, [TrustApprovalEventType::AdjustmentRequested, TrustApprovalEventType::AdjustmentFirstApproved], true)) {
            throw new \RuntimeException('This event is not a pending adjustment awaiting a decision.');
        }

        return TrustApprovalEvent::create([
            'firm_id' => $firm->id,
            'event_type' => TrustApprovalEventType::AdjustmentDenied,
            'actor_firm_user_id' => $deniedBy->id,
            'amount_cents' => $event->amount_cents,
            'matter_id' => $event->matter_id,
            'approved_entry_type' => TrustLedgerEntryType::Adjustment->value,
            'correlation_uuid' => $event->correlation_uuid,
            'trust_ledger_id' => $event->trust_ledger_id,
        ]);
    }

    /**
     * Requires an AdjustmentFirstApproved event, a second approver
     * distinct from the first (assertDistinctApprovers), and posts the
     * Adjustment entry inside one locked transaction. A debit adjustment
     * still cannot draw a matter's attributed balance below zero.
     */
    public function secondApprove(Firm $firm, TrustApprovalEvent $firstApprovedEvent, FirmUser $secondApprover): TrustLedgerEntry
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustApprovalEventBelongsToFirm($firstApprovedEvent, $firm);

        if ($firstApprovedEvent->event_type !== TrustApprovalEventType::AdjustmentFirstApproved) {
            throw new \RuntimeException('A second approval requires an AdjustmentFirstApproved trust_approval_events row.');
        }

        // Section 39A-3L, Checkpoint 4 - firm_users/matters are already
        // FORCE-RLS tables from earlier checkpoints (trust_ledgers is
        // not yet RLS-enabled at all — confirmed via pg_class:
        // relrowsecurity=false, relforcerowsecurity=false — so reading
        // it here is unaffected either way). These three reads used to
        // work only by accident, relying on
        // ambient database session context left active by an earlier
        // factory's context-hold create() pattern in the caller's flow.
        // EntitlementService::resolve() now correctly clears any such
        // ambient context when the eligibility check above returns, so
        // these three reads are combined into one explicit whole-call
        // wrap here rather than left unwrapped (and rather than each
        // getting its own separate wrap).
        [$firstApprover, $ledger, $matter] = (new TenantContextService())->runWithFirmContext($firm, fn () => [
            $firstApprovedEvent->actor,
            $firstApprovedEvent->trustLedger,
            $firstApprovedEvent->matter,
        ]);

        $this->accessPolicy->assertDistinctApprovers($firstApprover, $secondApprover);

        if (TrustLedgerEntry::query()->where('trust_approval_event_id', $firstApprovedEvent->id)->exists()) {
            throw new \RuntimeException('This adjustment approval has already been posted.');
        }

        $amountCentsDelta = $firstApprovedEvent->amount_cents;

        if ($matter) {
            $this->crossMatterProtection->assertMatterEligibleForLedger($matter, $ledger);
        }

        $entry = $this->lockService->withLockedBalances($ledger, $matter, function ($lockedBalance, $lockedMatterBalance) use (
            $firm, $ledger, $matter, $amountCentsDelta, $firstApprovedEvent
        ) {
            if ($lockedBalance->balance_cents + $amountCentsDelta < 0) {
                throw new \RuntimeException('This adjustment would draw the trust ledger balance below zero.');
            }

            if ($matter) {
                $this->crossMatterProtection->assertDebitKeepsMatterBalanceNonNegative($lockedMatterBalance, $amountCentsDelta);
            }

            $entry = TrustLedgerEntry::create([
                'firm_id' => $firm->id,
                'trust_ledger_id' => $ledger->id,
                'matter_id' => $matter?->id,
                'entry_type' => TrustLedgerEntryType::Adjustment,
                'amount_cents' => $amountCentsDelta,
                'trust_approval_event_id' => $firstApprovedEvent->id,
                'posted_at' => now(),
            ]);

            $this->balanceService->recomputeForLedger($ledger, $lockedBalance);

            if ($matter) {
                $this->balanceService->recomputeForMatter($ledger, $matter, $lockedMatterBalance);
            }

            return $entry;
        });

        TrustApprovalEvent::create([
            'firm_id' => $firm->id,
            'event_type' => TrustApprovalEventType::AdjustmentSecondApproved,
            'actor_firm_user_id' => $secondApprover->id,
            'amount_cents' => $amountCentsDelta,
            'matter_id' => $matter?->id,
            'approved_entry_type' => TrustLedgerEntryType::Adjustment->value,
            'correlation_uuid' => $firstApprovedEvent->correlation_uuid,
            'trust_ledger_id' => $ledger->id,
        ]);

        return $entry;
    }
}
