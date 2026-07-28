<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Models\FinancialEvidenceLargeDepositFlag;
use App\Models\FinancialEvidenceLargeDepositThreshold;
use App\Models\FinancialEvidenceTransaction;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\TenantContextService;

/**
 * FinancialEvidenceLargeDepositDetectionService — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.7). Config-driven
 * threshold rule: flags any `financial_evidence_transactions` row with
 * a positive `amount_cents` beyond the resolved threshold. Threshold
 * resolution reads `financial_evidence_large_deposit_thresholds`
 * (Global, no-RLS, `platform_default` -> `firm_override` — this is the
 * ONE reader of that table's `firm_override` row), falling back to
 * `config('financial_evidence.large_deposit_default_threshold_cents')`
 * if no `platform_default` row has been seeded either. Purely a
 * FirmsVault-generated OBSERVATION (provenance = `FirmsVaultObservation`).
 */
class FinancialEvidenceLargeDepositDetectionService
{
    public function __construct(
        private readonly FinancialEvidenceMatterScopeService $scope,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function resolveThresholdCents(Firm $firm): int
    {
        $firmOverride = FinancialEvidenceLargeDepositThreshold::firmOverrideFor($firm->id);

        if ($firmOverride !== null) {
            return (int) $firmOverride->threshold_cents;
        }

        $platformDefault = FinancialEvidenceLargeDepositThreshold::platformDefault();

        if ($platformDefault !== null) {
            return (int) $platformDefault->threshold_cents;
        }

        return (int) config('financial_evidence.large_deposit_default_threshold_cents', 1_000_000);
    }

    public function evaluate(Matter $matter): int
    {
        return $this->tenantContext->runWithFirmContext($matter->firm_id, function () use ($matter) {
            $thresholdCents = $this->resolveThresholdCents($matter->firm);
            $bankAccountIds = $this->scope->connectedBankAccountIds($matter);

            if ($bankAccountIds === []) {
                return 0;
            }

            $alreadyFlaggedTransactionIds = FinancialEvidenceLargeDepositFlag::query()
                ->where('matter_id', $matter->id)
                ->pluck('transaction_id')
                ->flip();

            $candidates = FinancialEvidenceTransaction::query()
                ->whereIn('bank_account_id', $bankAccountIds)
                ->where('amount_cents', '>=', $thresholdCents)
                ->get();

            $created = 0;

            foreach ($candidates as $transaction) {
                if ($alreadyFlaggedTransactionIds->has($transaction->id)) {
                    continue;
                }

                FinancialEvidenceLargeDepositFlag::query()->create([
                    'firm_id' => $matter->firm_id,
                    'matter_id' => $matter->id,
                    'transaction_id' => $transaction->id,
                    'threshold_cents_applied' => $thresholdCents,
                    'detected_at' => now(),
                ]);

                $created++;
            }

            return $created;
        });
    }
}
