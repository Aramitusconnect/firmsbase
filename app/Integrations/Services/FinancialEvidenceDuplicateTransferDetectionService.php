<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Models\FinancialEvidenceDuplicateTransferFlag;
use App\Models\FinancialEvidenceTransaction;
use App\Models\Matter;
use App\Services\TenantContextService;
use Illuminate\Support\Carbon;

/**
 * FinancialEvidenceDuplicateTransferDetectionService — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.7). Flags pairs of
 * `financial_evidence_transactions` rows across two of the matter's
 * connected accounts with matching amount (within the configured
 * window) and opposite sign — a potential internal transfer, never a
 * confirmed one. Purely a FirmsVault-generated OBSERVATION
 * (provenance = `FirmsVaultObservation`); this service never writes to
 * any `Trust*` table or class, and never imports one.
 *
 * `evaluate()` is idempotent per matter: a pair already flagged (open
 * or resolved) is never re-flagged.
 */
class FinancialEvidenceDuplicateTransferDetectionService
{
    public function __construct(
        private readonly FinancialEvidenceMatterScopeService $scope,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function evaluate(Matter $matter): int
    {
        return $this->tenantContext->runWithFirmContext($matter->firm_id, function () use ($matter) {
            $windowHours = (int) config('financial_evidence.duplicate_transfer_window_hours', 48);
            $bankAccountIds = $this->scope->connectedBankAccountIds($matter);

            if (count($bankAccountIds) < 2) {
                return 0;
            }

            $transactions = FinancialEvidenceTransaction::query()
                ->whereIn('bank_account_id', $bankAccountIds)
                ->orderBy('transaction_date')
                ->get();

            $alreadyFlagged = FinancialEvidenceDuplicateTransferFlag::query()
                ->where('matter_id', $matter->id)
                ->get()
                ->map(fn (FinancialEvidenceDuplicateTransferFlag $flag): string => $this->pairKey(
                    $flag->transaction_id_a,
                    $flag->transaction_id_b,
                ))
                ->flip();

            $created = 0;

            foreach ($transactions as $a) {
                foreach ($transactions as $b) {
                    if ($a->id >= $b->id || $a->bank_account_id === $b->bank_account_id) {
                        continue;
                    }

                    if ($a->amount_cents !== -$b->amount_cents) {
                        continue;
                    }

                    /** @var Carbon $aDate */
                    $aDate = $a->transaction_date;
                    /** @var Carbon $bDate */
                    $bDate = $b->transaction_date;

                    if (abs($aDate->diffInHours($bDate)) > $windowHours) {
                        continue;
                    }

                    $key = $this->pairKey($a->id, $b->id);

                    if ($alreadyFlagged->has($key)) {
                        continue;
                    }

                    FinancialEvidenceDuplicateTransferFlag::query()->create([
                        'firm_id' => $matter->firm_id,
                        'matter_id' => $matter->id,
                        'transaction_id_a' => $a->id,
                        'transaction_id_b' => $b->id,
                        'detected_at' => now(),
                    ]);

                    $alreadyFlagged->put($key, true);
                    $created++;
                }
            }

            return $created;
        });
    }

    private function pairKey(int $a, int $b): string
    {
        return $a < $b ? "{$a}:{$b}" : "{$b}:{$a}";
    }
}
