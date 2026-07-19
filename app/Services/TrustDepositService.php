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
 * TrustDepositService — the ONLY writer of Deposit-type
 * trust_ledger_entries rows, and the owner of the deposit
 * request/approve/deny lifecycle (there is no separate
 * trust_deposit_requests table, so this lifecycle lives entirely in
 * trust_approval_events — correction #3).
 *
 * Trust deposits are recorded ONLY through this gated path, directly
 * into trust_ledger_entries. This service NEVER creates a generic
 * `payments` row and NEVER requests PaymentClassification::TrustIoltaPayment
 * — the generic payment flow remains exactly as blocked as it was
 * before Phase 13 (see Phase13GenericPaymentFlowStillBlockedTest).
 */
class TrustDepositService
{
    public function __construct(
        private readonly TrustEligibilityService $eligibility,
        private readonly TrustAccessPolicyService $accessPolicy,
        private readonly TenantSafeTrustPolicyService $tenantSafePolicy,
        private readonly TrustConcurrencyLockService $lockService,
        private readonly TrustBalanceService $balanceService,
    ) {
    }

    public function requestDeposit(
        Firm $firm,
        TrustLedger $ledger,
        FirmUser $requestedBy,
        int $amountCents,
        ?Matter $matter = null,
    ): TrustApprovalEvent {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustLedgerBelongsToFirm($ledger, $firm);
        $this->accessPolicy->assertCanRequest($requestedBy);

        if ($amountCents <= 0) {
            throw new \RuntimeException('Deposit amount must be positive.');
        }

        return (new TenantContextService())->runWithFirmContext($firm, fn () => TrustApprovalEvent::create([
            'firm_id' => $firm->id,
            'event_type' => TrustApprovalEventType::DepositRequested,
            'actor_firm_user_id' => $requestedBy->id,
            'amount_cents' => $amountCents,
            'matter_id' => $matter?->id,
            'approved_entry_type' => TrustLedgerEntryType::Deposit->value,
            'correlation_uuid' => (string) Str::uuid7(),
            'trust_ledger_id' => $ledger->id,
        ]));
    }

    public function approveDeposit(Firm $firm, TrustApprovalEvent $requestedEvent, FirmUser $approvedBy): TrustApprovalEvent
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustApprovalEventBelongsToFirm($requestedEvent, $firm);
        $this->accessPolicy->assertCanApprove($approvedBy);

        if ($requestedEvent->event_type !== TrustApprovalEventType::DepositRequested) {
            throw new \RuntimeException('This event is not a pending deposit request.');
        }

        return (new TenantContextService())->runWithFirmContext($firm, fn () => TrustApprovalEvent::create([
            'firm_id' => $firm->id,
            'event_type' => TrustApprovalEventType::DepositApproved,
            'actor_firm_user_id' => $approvedBy->id,
            'amount_cents' => $requestedEvent->amount_cents,
            'matter_id' => $requestedEvent->matter_id,
            'approved_entry_type' => TrustLedgerEntryType::Deposit->value,
            'correlation_uuid' => $requestedEvent->correlation_uuid,
            'trust_ledger_id' => $requestedEvent->trust_ledger_id,
        ]));
    }

    public function denyDeposit(Firm $firm, TrustApprovalEvent $requestedEvent, FirmUser $deniedBy): TrustApprovalEvent
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustApprovalEventBelongsToFirm($requestedEvent, $firm);
        $this->accessPolicy->assertCanApprove($deniedBy);

        return (new TenantContextService())->runWithFirmContext($firm, fn () => TrustApprovalEvent::create([
            'firm_id' => $firm->id,
            'event_type' => TrustApprovalEventType::DepositDenied,
            'actor_firm_user_id' => $deniedBy->id,
            'amount_cents' => $requestedEvent->amount_cents,
            'matter_id' => $requestedEvent->matter_id,
            'approved_entry_type' => TrustLedgerEntryType::Deposit->value,
            'correlation_uuid' => $requestedEvent->correlation_uuid,
            'trust_ledger_id' => $requestedEvent->trust_ledger_id,
        ]));
    }

    /**
     * Posts the Deposit ledger entry. Requires a matching, UNCONSUMED
     * DepositApproved event — matched on structured columns
     * (trust_ledger_id, matter_id, amount_cents), never on
     * metadata_json (correction #3/#17's required test). "Unconsumed"
     * means no trust_ledger_entries row already references this exact
     * approval event id.
     */
    public function post(Firm $firm, TrustLedger $ledger, TrustApprovalEvent $depositApprovedEvent, ?Matter $matter = null): TrustLedgerEntry
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustLedgerBelongsToFirm($ledger, $firm);
        $this->tenantSafePolicy->assertTrustApprovalEventBelongsToFirm($depositApprovedEvent, $firm);

        if ($depositApprovedEvent->event_type !== TrustApprovalEventType::DepositApproved) {
            throw new \RuntimeException('A Deposit entry requires a DepositApproved trust_approval_events row.');
        }

        if ($depositApprovedEvent->trust_ledger_id !== $ledger->id) {
            throw new \RuntimeException('The approval event does not match this trust ledger.');
        }

        if ($depositApprovedEvent->matter_id !== $matter?->id) {
            throw new \RuntimeException('The approval event does not match the given matter.');
        }

        // Wave 10 - whole-method wrap spans from the pre-flight
        // duplicate-check (queries trust_ledger_entries directly, and
        // would otherwise silently fail closed under FORCE RLS, masking
        // a real already-posted deposit as "never posted") through the
        // entire lockService->withLockedBalances() closure below.
        return (new TenantContextService())->runWithFirmContext($firm, function () use ($firm, $ledger, $matter, $depositApprovedEvent) {
            if (TrustLedgerEntry::query()->where('trust_approval_event_id', $depositApprovedEvent->id)->exists()) {
                throw new \RuntimeException('This deposit approval has already been posted.');
            }

            $amountCents = $depositApprovedEvent->amount_cents;

            return $this->lockService->withLockedBalances($ledger, $matter, function ($lockedBalance, $lockedMatterBalance) use (
                $firm, $ledger, $matter, $amountCents, $depositApprovedEvent
            ) {
                $entry = TrustLedgerEntry::create([
                    'firm_id' => $firm->id,
                    'trust_ledger_id' => $ledger->id,
                    'matter_id' => $matter?->id,
                    'entry_type' => TrustLedgerEntryType::Deposit,
                    'amount_cents' => $amountCents,
                    'trust_approval_event_id' => $depositApprovedEvent->id,
                    'posted_at' => now(),
                ]);

                $this->balanceService->recomputeForLedger($ledger, $lockedBalance);

                if ($matter) {
                    $this->balanceService->recomputeForMatter($ledger, $matter, $lockedMatterBalance);
                }

                return $entry;
            });
        });
    }
}
