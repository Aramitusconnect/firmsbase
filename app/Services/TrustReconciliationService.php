<?php

namespace App\Services;

use App\Enums\TrustApprovalEventType;
use App\Enums\TrustReconciliationStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TrustAccount;
use App\Models\TrustApprovalEvent;
use App\Models\TrustLedger;
use App\Models\TrustReconciliation;
use Illuminate\Support\Str;

/**
 * TrustReconciliationService — a periodic, firm-initiated, manually
 * asserted reconciliation of the system's cached trust balances against
 * the firm's real bank statement balance for a TrustAccount. Every
 * ledger under the account is first reconciled cache-vs-ledger via
 * TrustBalanceService::reconcileCacheAgainstLedger() (defense in depth
 * against a stale cache), then the SUM of those (now-verified) cached
 * balances is compared to the manually-asserted bank balance.
 *
 * A Discrepancy is recorded as-is and NEVER auto-corrected by this or
 * any other service (project rule) — resolving a discrepancy requires
 * a human-reviewed TrustHighRiskAdjustmentService adjustment afterward,
 * a deliberate separate action, never an automatic side effect of
 * running a reconciliation.
 */
class TrustReconciliationService
{
    public function __construct(
        private readonly TenantSafeTrustPolicyService $tenantSafePolicy,
        private readonly TrustBalanceService $balanceService,
        private readonly TrustEligibilityService $eligibility,
    ) {
    }

    /**
     * The entire method body — from the $account->ledgers relation load
     * through the final TrustApprovalEvent::create() — runs inside one
     * runWithFirmContext($firm, ...) call. Without it, $account->ledgers
     * (a HasMany on TrustLedger, gated by BelongsToTenant's global scope
     * plus, once forced, trust_ledgers' own RLS policy) silently returns
     * an EMPTY collection rather than throwing, which would leave
     * $systemBalanceCents at 0 for the entire loop and misreport a real
     * discrepancy as Balanced — a fail-open bug, not merely a fail-
     * closed inconvenience. The two pre-flight, in-memory-only
     * assertions run before the wrap opens since neither issues a query
     * against a tenant-owned table.
     */
    public function run(
        Firm $firm,
        TrustAccount $account,
        FirmUser $performedBy,
        \DateTimeInterface $periodStart,
        \DateTimeInterface $periodEnd,
        int $assertedBankBalanceCents,
    ): TrustReconciliation {
        $this->tenantSafePolicy->assertTrustAccountBelongsToFirm($account, $firm);
        $this->eligibility->assertEligible($firm);

        return (new TenantContextService())->runWithFirmContext($firm, function () use (
            $firm, $account, $performedBy, $periodStart, $periodEnd, $assertedBankBalanceCents
        ) {
            $systemBalanceCents = 0;
            $ledgersSeen = 0;

            foreach ($account->ledgers as $ledger) {
                $ledgersSeen++;
                $cacheCheck = $this->balanceService->reconcileCacheAgainstLedger($ledger);

                if (! $cacheCheck->matches) {
                    throw new \RuntimeException(
                        "TrustLedger [id={$ledger->id}] cached balance does not match its own ledger entries; ".
                        'resolve this cache discrepancy before running a bank reconciliation.'
                    );
                }

                $systemBalanceCents += $cacheCheck->cachedBalanceCents;
            }

            // Defensive check, independent of the wrap above: a genuinely
            // new TrustAccount with zero ledgers yet is a legitimate real
            // state (allowed). But if trust_ledgers rows for this account
            // DO exist yet the relation returned none under this
            // reconciliation's own tenant context, that is a silent,
            // dangerous mismatch — refuse to record a result rather than
            // let it masquerade as a real zero-ledger reconciliation.
            if ($ledgersSeen === 0 && TrustLedger::query()->where('trust_account_id', $account->id)->exists()) {
                throw new \RuntimeException(
                    "TrustAccount [id={$account->id}] appears to have ledgers, but none were loaded under ".
                    "this reconciliation's tenant context; refusing to record a reconciliation result."
                );
            }

            $discrepancyCents = $systemBalanceCents - $assertedBankBalanceCents;
            $status = $discrepancyCents === 0 ? TrustReconciliationStatus::Balanced : TrustReconciliationStatus::Discrepancy;

            $reconciliation = TrustReconciliation::create([
                'firm_id' => $firm->id,
                'trust_account_id' => $account->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'system_balance_cents' => $systemBalanceCents,
                'asserted_bank_balance_cents' => $assertedBankBalanceCents,
                'discrepancy_cents' => $discrepancyCents,
                'status' => $status,
                'performed_by_firm_user_id' => $performedBy->id,
                'completed_at' => now(),
            ]);

            TrustApprovalEvent::create([
                'firm_id' => $firm->id,
                'event_type' => TrustApprovalEventType::ReconciliationCompleted,
                'actor_firm_user_id' => $performedBy->id,
                'amount_cents' => $discrepancyCents,
                'correlation_uuid' => (string) Str::uuid7(),
            ]);

            return $reconciliation;
        });
    }
}
