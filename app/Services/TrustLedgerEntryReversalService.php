<?php

namespace App\Services;

use App\Enums\TrustLedgerEntryType;
use App\Models\Firm;
use App\Models\TrustLedger;
use App\Models\TrustLedgerEntry;

/**
 * TrustLedgerEntryReversalService — the ONLY way to correct an
 * already-posted trust_ledger_entries row (approved correction #5,
 * strict design). Creates a brand-new row with the exact opposite
 * amount, entry_type = Reversal by default (TrustChargebackService may
 * pass ChargebackReversal instead — the ONLY caller-supplied override),
 * and reverses_entry_id pointing at the original. The original row's
 * fields are NEVER read for the purpose of writing to it — this
 * service never calls update() or delete() on the original entry, only
 * create() for the new one.
 */
class TrustLedgerEntryReversalService
{
    public function __construct(
        private readonly TenantSafeTrustPolicyService $tenantSafePolicy,
        private readonly TrustConcurrencyLockService $lockService,
        private readonly TrustBalanceService $balanceService,
    ) {
    }

    public function reverse(
        Firm $firm,
        TrustLedger $ledger,
        TrustLedgerEntry $originalEntry,
        ?TrustLedgerEntryType $entryTypeOverride = null,
    ): TrustLedgerEntry {
        $this->tenantSafePolicy->assertTrustLedgerBelongsToFirm($ledger, $firm);
        $this->tenantSafePolicy->assertTrustLedgerEntryBelongsToFirm($originalEntry, $firm);

        if ($originalEntry->trust_ledger_id !== $ledger->id) {
            throw new \RuntimeException('The entry being reversed does not belong to this ledger.');
        }

        if ($originalEntry->entry_type === TrustLedgerEntryType::Reversal
            || $originalEntry->entry_type === TrustLedgerEntryType::ChargebackReversal) {
            throw new \RuntimeException('A reversal entry cannot itself be reversed.');
        }

        // Wave 10 - one outer wrap spans the ENTIRE remainder of this
        // method. The already-reversed duplicate-check below queries
        // trust_ledger_entries directly and moves inside this wrap
        // (rather than staying above it, unwrapped) — under FORCE RLS an
        // unwrapped ->exists() check would silently evaluate against zero
        // visible rows and mask a real duplicate reversal attempt as
        // "never reversed" instead of correctly detecting it, the same
        // fail-closed-masking risk flagged in
        // TrustHighRiskAdjustmentService::secondApprove(). The
        // pre-existing narrow matters-read wrap survives unchanged as a
        // nested child — same $firm throughout.
        return (new TenantContextService())->runWithFirmContext($firm, function () use ($firm, $ledger, $originalEntry, $entryTypeOverride) {
            if (TrustLedgerEntry::query()->where('reverses_entry_id', $originalEntry->id)->exists()) {
                throw new \RuntimeException('This entry has already been reversed.');
            }

            $matter = (new TenantContextService())->runWithFirmContext($firm, fn () => $originalEntry->matter);
            $oppositeAmount = -1 * $originalEntry->amount_cents;

            return $this->lockService->withLockedBalances($ledger, $matter, function ($lockedBalance, $lockedMatterBalance) use (
                $firm, $ledger, $matter, $originalEntry, $oppositeAmount, $entryTypeOverride
            ) {
                $reversal = TrustLedgerEntry::create([
                    'firm_id' => $firm->id,
                    'trust_ledger_id' => $ledger->id,
                    'matter_id' => $matter?->id,
                    'entry_type' => $entryTypeOverride ?? TrustLedgerEntryType::Reversal,
                    'amount_cents' => $oppositeAmount,
                    'reverses_entry_id' => $originalEntry->id,
                    'posted_at' => now(),
                ]);

                $this->balanceService->recomputeForLedger($ledger, $lockedBalance);

                if ($matter) {
                    $this->balanceService->recomputeForMatter($ledger, $matter, $lockedMatterBalance);
                }

                return $reversal;
            });
        });
    }
}
