<?php

namespace App\Services;

use App\Enums\TrustApprovalEventType;
use App\Enums\TrustReconciliationStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TrustAccount;
use App\Models\TrustApprovalEvent;
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
    ) {
    }

    public function run(
        Firm $firm,
        TrustAccount $account,
        FirmUser $performedBy,
        \DateTimeInterface $periodStart,
        \DateTimeInterface $periodEnd,
        int $assertedBankBalanceCents,
    ): TrustReconciliation {
        $this->tenantSafePolicy->assertTrustAccountBelongsToFirm($account, $firm);

        $systemBalanceCents = 0;

        foreach ($account->ledgers as $ledger) {
            $cacheCheck = $this->balanceService->reconcileCacheAgainstLedger($ledger);

            if (! $cacheCheck->matches) {
                throw new \RuntimeException(
                    "TrustLedger [id={$ledger->id}] cached balance does not match its own ledger entries; ".
                    'resolve this cache discrepancy before running a bank reconciliation.'
                );
            }

            $systemBalanceCents += $cacheCheck->cachedBalanceCents;
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
    }
}
