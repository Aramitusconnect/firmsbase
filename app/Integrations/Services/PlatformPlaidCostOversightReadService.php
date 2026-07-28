<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\ProviderBillableCallReservation;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\TenantContextService;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * PlatformPlaidCostOversightReadService — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §3). Backs
 * `PlaidCostOversightPage`. Aggregates
 * `provider_billable_call_reservations` (Direct `BelongsToTenant`,
 * FORCE RLS) **BY FIRM, never by individual transaction** — every
 * number returned is a SUM/COUNT, never a drill-down into what was
 * purchased. "Unallocated usage" = reservations with
 * `rate_card_entry_id IS NULL`, summed by firm (§1.3's null-cost
 * discipline — an unpriced reservation's dollar total is never
 * coalesced to 0, it is counted separately as "unallocated").
 */
final class PlatformPlaidCostOversightReadService
{
    public function __construct(
        private readonly PlatformStaffAccessPolicyService $accessPolicy,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function assertCanAccess(PlatformAdmin $admin): void
    {
        $decision = $this->accessPolicy->canAccessIntegrationOversight($admin);

        if (! $decision->allowed) {
            throw new RuntimeException($decision->reason ?? 'Not permitted to access Plaid cost oversight.');
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function overviewByFirm(PlatformAdmin $admin): Collection
    {
        $this->assertCanAccess($admin);

        $firms = Firm::query()->orderBy('name')->orderBy('id')->get();

        $rows = collect();

        foreach ($firms as $firm) {
            $firmRow = $this->tenantContext->runWithFirmContext($firm, function () use ($firm): ?array {
                $allocated = ProviderBillableCallReservation::query()
                    ->where('firm_id', $firm->id)
                    ->where('provider_key', ProviderKey::Plaid->value)
                    ->whereNotNull('rate_card_entry_id')
                    ->selectRaw('COUNT(*) as call_count, SUM(estimated_customer_price_cents) as total_cents')
                    ->first();

                $unallocated = ProviderBillableCallReservation::query()
                    ->where('firm_id', $firm->id)
                    ->where('provider_key', ProviderKey::Plaid->value)
                    ->whereNull('rate_card_entry_id')
                    ->count();

                $liveBalanceCalls = ProviderBillableCallReservation::query()
                    ->where('firm_id', $firm->id)
                    ->where('provider_key', ProviderKey::Plaid->value)
                    ->where('product', 'balance')
                    ->where('billing_operation', 'get')
                    ->count();

                $totalCalls = (int) ($allocated->call_count ?? 0) + $unallocated;

                if ($totalCalls === 0) {
                    return null;
                }

                return [
                    'firm_uuid' => $firm->uuid,
                    'firm_name' => $firm->name,
                    'allocated_call_count' => (int) ($allocated->call_count ?? 0),
                    'estimated_customer_cost_cents' => $allocated->total_cents !== null ? (int) $allocated->total_cents : null,
                    'unallocated_call_count' => $unallocated,
                    'live_balance_call_count' => $liveBalanceCalls,
                    'total_call_count' => $totalCalls,
                ];
            });

            if ($firmRow !== null) {
                $rows->push($firmRow);
            }
        }

        return $rows->values();
    }
}
