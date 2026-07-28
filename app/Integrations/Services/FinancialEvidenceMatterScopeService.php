<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Models\FinancialEvidenceBankAccount;
use App\Models\FinancialEvidenceMatterAuthorization;
use App\Models\Matter;

/**
 * FinancialEvidenceMatterScopeService — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on"). The one shared
 * helper every Financial Evidence Workspace panel/detection service
 * uses to resolve "which `firm_integrations`/bank accounts are
 * currently authorized for this matter" from
 * `financial_evidence_matter_authorizations` — never re-derived
 * independently by each caller, so a renewal/revocation is honored
 * identically everywhere.
 */
class FinancialEvidenceMatterScopeService
{
    /**
     * @return array<int, int> firm_integration_id values currently
     *                         authorized (not superseded) for this
     *                         matter
     */
    public function connectedFirmIntegrationIds(Matter $matter): array
    {
        return FinancialEvidenceMatterAuthorization::query()
            ->where('matter_id', $matter->id)
            ->whereNull('superseded_at')
            ->pluck('firm_integration_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, int> financial_evidence_bank_accounts.id
     *                         values reachable through this matter's
     *                         currently-authorized connections
     */
    public function connectedBankAccountIds(Matter $matter): array
    {
        $firmIntegrationIds = $this->connectedFirmIntegrationIds($matter);

        if ($firmIntegrationIds === []) {
            return [];
        }

        return FinancialEvidenceBankAccount::query()
            ->whereIn('firm_integration_id', $firmIntegrationIds)
            ->pluck('id')
            ->all();
    }
}
