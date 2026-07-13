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

        if (TrustLedgerEntry::query()->where('reverses_entry_id', $originalEntry->id)->exists()) {
            throw new \RuntimeException('This entry has already been reversed.');
        }

        // Section 39A-3L, Checkpoint 4 - matters is already a FORCE-RLS
        // table from an earlier checkpoint (trust_ledgers is not yet
        // RLS-enabled at all). This read used to work only by accident,
        // relying on ambient database session context left active by
        // MatterFactory's context-hold create() pattern earlier in the
        // caller's flow (this method is reached via
        // TrustChargebackService::reverse(), whose own assertEligible()
        // call now routes through the fixed EntitlementService::
        // resolve() and correctly clears any such ambient context
        // before returning here). With $matter silently null, the
        // `if ($matter)` gates below would silently SKIP
        // recomputeForMatter() instead of failing closed.
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
    }
}
