<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Exceptions\TenantIsolationException;
use App\Integrations\Models\FirmIntegration;
use App\Models\Firm;

/**
 * ProviderTenantSafePolicyService — pipeline step 2
 * (checkpoint4-design-cost-control.md §2 step 2). Structural copy of
 * `App\Services\TenantSafeTrustPolicyService::assertTrustAccountBelongsToFirm()`
 * — a plain, in-memory, defense-in-depth check, run BEFORE any tenant
 * context wrap so it never depends on ambient context being right.
 */
class ProviderTenantSafePolicyService
{
    public function assertConnectionBelongsToFirm(FirmIntegration $connection, Firm $firm): void
    {
        if ((int) $connection->firm_id !== (int) $firm->id) {
            throw new TenantIsolationException(
                "FirmIntegration [id={$connection->id}] does not belong to firm [id={$firm->id}]."
            );
        }
    }
}
