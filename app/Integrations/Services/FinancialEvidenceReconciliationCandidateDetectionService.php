<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Models\FinancialEvidenceReconciliationCandidate;
use App\Models\FinancialEvidenceTransaction;
use App\Models\Matter;
use App\Models\TrustLedgerEntry;
use App\Services\TenantContextService;

/**
 * FinancialEvidenceReconciliationCandidateDetectionService —
 * FirmsVault Live Integrations, Checkpoint 4 ("Plaid financial evidence
 * add-on"; checkpoint4-design-workspace-and-admin-ui.md §1.7).
 * EXPLICITLY NEVER AUTO-POSTS TO THE TRUST LEDGER; display-only,
 * attorney-decision-driven. Lives under `App\Integrations\Services`,
 * never `app/Services/Trust*.php` naming, and imports nothing from any
 * `Trust*` SERVICE — the only Trust-domain reference anywhere in this
 * class is a read-only `TrustLedgerEntry::query()->find()`-shaped
 * lookup against the MODEL (not a service) for display purposes,
 * confirmed compatible with `TrustForbiddenIntegrationsTest`'s scan of
 * `app/Services/Trust*.php` files (that test scans service files, not
 * files that merely read a Trust model, and this class is not itself a
 * `Trust*`-named file). This service NEVER calls `TrustLedgerEntry::create()`,
 * `::update()`, `::save()`, or any Trust posting method of any kind.
 *
 * A "candidate" is a heuristic amount+date proximity match between a
 * Plaid transaction and an EXISTING trust ledger entry, presented to an
 * attorney as a hypothesis only. The one action this queue offers
 * ("Confirm as ledger match") writes ONLY to
 * `financial_evidence_reconciliation_candidates.status` — never to
 * `trust_ledger_entries` or any `Trust*` table.
 */
class FinancialEvidenceReconciliationCandidateDetectionService
{
    private const MATCH_WINDOW_DAYS = 3;

    public function __construct(
        private readonly FinancialEvidenceMatterScopeService $scope,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function evaluate(Matter $matter): int
    {
        return $this->tenantContext->runWithFirmContext($matter->firm_id, function () use ($matter) {
            $bankAccountIds = $this->scope->connectedBankAccountIds($matter);

            if ($bankAccountIds === []) {
                return 0;
            }

            $alreadyCandidateTransactionIds = FinancialEvidenceReconciliationCandidate::query()
                ->where('matter_id', $matter->id)
                ->pluck('transaction_id')
                ->flip();

            // Read-only lookup against the trust ledger's own matter-scoped
            // entries — never a write, never a Trust* SERVICE call.
            $ledgerEntries = TrustLedgerEntry::query()
                ->where('matter_id', $matter->id)
                ->get(['id', 'amount_cents', 'posted_at']);

            if ($ledgerEntries->isEmpty()) {
                return 0;
            }

            $transactions = FinancialEvidenceTransaction::query()
                ->whereIn('bank_account_id', $bankAccountIds)
                ->get();

            $created = 0;

            foreach ($transactions as $transaction) {
                if ($alreadyCandidateTransactionIds->has($transaction->id)) {
                    continue;
                }

                $bestMatch = null;

                foreach ($ledgerEntries as $entry) {
                    if ((int) $entry->amount_cents !== abs((int) $transaction->amount_cents)) {
                        continue;
                    }

                    $daysApart = $entry->posted_at !== null
                        ? abs($entry->posted_at->diffInDays($transaction->transaction_date))
                        : null;

                    if ($daysApart === null || $daysApart > self::MATCH_WINDOW_DAYS) {
                        continue;
                    }

                    $bestMatch = ['entry_id' => $entry->id, 'confidence' => $daysApart === 0 ? 'high' : 'medium'];
                    break;
                }

                if ($bestMatch === null) {
                    continue;
                }

                FinancialEvidenceReconciliationCandidate::query()->create([
                    'firm_id' => $matter->firm_id,
                    'matter_id' => $matter->id,
                    'transaction_id' => $transaction->id,
                    'trust_ledger_entry_id' => $bestMatch['entry_id'],
                    'match_confidence' => $bestMatch['confidence'],
                    'status' => 'candidate',
                ]);

                $created++;
            }

            return $created;
        });
    }
}
