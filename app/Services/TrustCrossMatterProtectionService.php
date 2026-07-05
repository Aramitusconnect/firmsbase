<?php

namespace App\Services;

use App\Models\Matter;
use App\Models\MatterTrustBalance;
use App\Models\TrustLedger;

/**
 * TrustCrossMatterProtectionService — explicit, individually-testable
 * enforcement of "no cross-matter use of trust funds" (project rule /
 * correction #10). Every money-moving service that debits a specific
 * matter's attributed balance must call assertDebitKeepsMatterBalanceNonNegative()
 * BEFORE persisting the debit, using the LOCKED matter balance row
 * supplied by TrustConcurrencyLockService — never a stale read.
 */
class TrustCrossMatterProtectionService
{
    public function __construct(private readonly TenantSafeTrustPolicyService $tenantSafePolicy)
    {
    }

    /**
     * Matter must belong to the same firm AND the same client as the
     * trust ledger — a matter cannot draw on a different client's
     * trust funds even within the same firm.
     */
    public function assertMatterEligibleForLedger(Matter $matter, TrustLedger $ledger): void
    {
        $this->tenantSafePolicy->assertMatterMatchesLedger($matter, $ledger);
    }

    /**
     * amountCentsDelta is signed exactly like a trust_ledger_entries
     * row (negative for a debit). Given the LOCKED current balance,
     * rejects any operation that would leave the matter's attributed
     * balance below zero.
     */
    public function assertDebitKeepsMatterBalanceNonNegative(?MatterTrustBalance $lockedMatterBalance, int $amountCentsDelta): void
    {
        $currentBalance = $lockedMatterBalance?->balance_cents ?? 0;

        if ($currentBalance + $amountCentsDelta < 0) {
            throw new \RuntimeException('This operation would draw the matter\'s attributed trust balance below zero.');
        }
    }
}
