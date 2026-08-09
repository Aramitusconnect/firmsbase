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
    ) {}

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

        // TrustLedgerEntryReversalService::reverse() has no amount
        // parameter -- it always reverses the FULL original entry
        // amount, regardless of what is stored here. Without this
        // check, a reported amount could silently diverge from what
        // reverse() actually posts (partial chargebacks are not
        // structurally supported), so the reported amount must exactly
        // match the original deposit to keep the two numbers honest.
        if ($amountCents !== $originalEntry->amount_cents) {
            throw new \RuntimeException('Chargeback amount must exactly match the original deposit amount; partial chargebacks are not supported.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, fn () => TrustChargebackEvent::create([
            'firm_id' => $firm->id,
            'original_trust_ledger_entry_id' => $originalEntry->id,
            'amount_cents' => $amountCents,
            'reason' => $reason,
            'status' => TrustChargebackStatus::Reported,
            'reported_at' => now(),
        ]));
    }

    /**
     * This method previously had ZERO tenant-context wrap of any kind:
     * $chargeback->originalEntry and $originalEntry->trustLedger were
     * both lazy-loaded with no context, and $originalEntry->trustLedger
     * was accessed with no null-safety, risking a raw PHP crash rather
     * than a named exception once trust_ledger_entries/trust_ledgers
     * are FORCE-RLS'd and either lazy-load silently resolves to null.
     * Both problems are fixed together: a whole-method wrap (covering
     * both lazy-loads, the nested — already independently wrapped per
     * TrustLedgerEntryReversalService::reverse() — call into the
     * reversal service, the update(), and the trailing fresh()) plus a
     * defensive null-check immediately after both lazy-loads resolve.
     */
    public function reverse(Firm $firm, TrustChargebackEvent $chargeback, FirmUser $reversedBy): TrustChargebackEvent
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustChargebackEventBelongsToFirm($chargeback, $firm);
        $this->accessPolicy->assertCanApprove($reversedBy);

        if ($chargeback->status !== TrustChargebackStatus::Reported) {
            throw new \RuntimeException('Only a Reported chargeback can be reversed.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $chargeback) {
            $originalEntry = $chargeback->originalEntry;
            $ledger = $originalEntry?->trustLedger;

            if ($originalEntry === null || $ledger === null) {
                throw new \RuntimeException(
                    "TrustChargebackEvent [id={$chargeback->id}]'s original ledger entry or its trust ledger ".
                    "could not be resolved under firm [id={$firm->id}]'s tenant context."
                );
            }

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
        });
    }

    public function resolve(Firm $firm, TrustChargebackEvent $chargeback, FirmUser $resolvedBy): TrustChargebackEvent
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustChargebackEventBelongsToFirm($chargeback, $firm);
        $this->accessPolicy->assertCanApprove($resolvedBy);

        if ($chargeback->status !== TrustChargebackStatus::Reversed) {
            throw new \RuntimeException('Only a Reversed chargeback can be marked Resolved.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($chargeback) {
            $chargeback->update([
                'status' => TrustChargebackStatus::Resolved,
                'resolved_at' => now(),
            ]);

            return $chargeback->fresh();
        });
    }
}
