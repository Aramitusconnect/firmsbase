<?php

namespace App\Services;

use App\Enums\TrustChargebackStatus;
use App\Enums\TrustLedgerEntryType;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TrustChargebackEvent;
use App\Models\TrustLedgerEntry;

/**
 * TrustChargebackService — records the externally-reported fact of a
 * chargeback against a previously-posted Deposit entry, then reverses
 * it via TrustLedgerEntryReversalService (passing the ChargebackReversal
 * entry-type override so the reversal is distinguishable in the ledger
 * from an ordinary correction). The ORIGINAL deposit entry is never
 * mutated — only a new opposite-signed row is created, exactly like any
 * other reversal.
 */
class TrustChargebackService
{
    public function __construct(
        private readonly TrustEligibilityService $eligibility,
        private readonly TrustAccessPolicyService $accessPolicy,
        private readonly TenantSafeTrustPolicyService $tenantSafePolicy,
        private readonly TrustLedgerEntryReversalService $reversalService,
    ) {
    }

    public function report(
        Firm $firm,
        TrustLedgerEntry $originalEntry,
        FirmUser $reportedBy,
        int $amountCents,
        string $reason,
    ): TrustChargebackEvent {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustLedgerEntryBelongsToFirm($originalEntry, $firm);
        $this->accessPolicy->assertCanRequest($reportedBy);

        if ($originalEntry->entry_type !== TrustLedgerEntryType::Deposit) {
            throw new \RuntimeException('A chargeback can only be reported against a Deposit entry.');
        }

        return TrustChargebackEvent::create([
            'firm_id' => $firm->id,
            'original_trust_ledger_entry_id' => $originalEntry->id,
            'amount_cents' => $amountCents,
            'reason' => $reason,
            'status' => TrustChargebackStatus::Reported,
            'reported_at' => now(),
        ]);
    }

    public function reverse(Firm $firm, TrustChargebackEvent $chargeback, FirmUser $reversedBy): TrustChargebackEvent
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustChargebackEventBelongsToFirm($chargeback, $firm);
        $this->accessPolicy->assertCanApprove($reversedBy);

        if ($chargeback->status !== TrustChargebackStatus::Reported) {
            throw new \RuntimeException('Only a Reported chargeback can be reversed.');
        }

        $originalEntry = $chargeback->originalEntry;
        $ledger = $originalEntry->trustLedger;

        $reversalEntry = $this->reversalService->reverse(
            $firm,
            $ledger,
            $originalEntry,
            TrustLedgerEntryType::ChargebackReversal,
        );

        $chargeback->update([
            'reversal_trust_ledger_entry_id' => $reversalEntry->id,
            'status' => TrustChargebackStatus::Reversed,
        ]);

        return $chargeback->fresh();
    }

    public function resolve(Firm $firm, TrustChargebackEvent $chargeback, FirmUser $resolvedBy): TrustChargebackEvent
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustChargebackEventBelongsToFirm($chargeback, $firm);
        $this->accessPolicy->assertCanApprove($resolvedBy);

        if ($chargeback->status !== TrustChargebackStatus::Reversed) {
            throw new \RuntimeException('Only a Reversed chargeback can be marked Resolved.');
        }

        $chargeback->update([
            'status' => TrustChargebackStatus::Resolved,
            'resolved_at' => now(),
        ]);

        return $chargeback->fresh();
    }
}
